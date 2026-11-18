"""Tests for 'Open an extra time' (jm-booking 2.1.0) against the throwaway WordPress + mock Stripe."""
import hashlib, hmac, json, subprocess, time, urllib.error, urllib.request, datetime

WP = "http://127.0.0.1:18080/?rest_route=/jmb/v1"
ok = bad = 0


def check(name, cond, extra=""):
    global ok, bad
    ok, bad = (ok + 1, bad) if cond else (ok, bad + 1)
    print(("PASS " if cond else "FAIL ") + name + (f"   [{extra}]" if not cond else ""))


def get_raw(url):
    with urllib.request.urlopen(url) as r: return r.read().decode()


def avail(month):
    raw = get_raw(f"{WP}/availability&month={month}")
    return raw, json.loads(raw[raw.index("{"):])


def slots(month, day):
    return [(s["kind"], s["start"], s.get("note", "")) for s in avail(month)[1]["days"][day]]


def db(sql):
    out = subprocess.run(["docker", "exec", "jmbtest-db", "mariadb", "-uwp", "-pwp", "wp", "-N", "-e", sql], capture_output=True, text=True).stdout
    return [l.split("\t") for l in out.strip().splitlines()]


def wpeval(code):
    return subprocess.run([str(__import__("pathlib").Path(__file__).with_name("wpcli.sh")), "eval", code], capture_output=True, text=True).stdout


def set_extras(lst):
    wpeval("update_option('jmb_extra', json_decode('" + json.dumps(lst) + "', true), false);")


def book(date, start, email, xff):
    body = json.dumps({"kind": "full", "date": date, "start": start, "name": "T " + email, "email": email, "location": "Mayfield Park", "return_url": "http://127.0.0.1:18080/book/"}).encode()
    r = urllib.request.Request(WP + "/book", data=body, method="POST", headers={"Content-Type": "application/json", "X-Forwarded-For": xff})
    try:
        with urllib.request.urlopen(r) as resp: return resp.status, json.loads(resp.read())
    except urllib.error.HTTPError as e: return e.code, json.loads(e.read())


def pay(bid):
    sid = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={bid}")[0][0]
    payload = json.dumps({"type": "checkout.session.completed", "data": {"object": {"id": sid, "client_reference_id": str(bid), "metadata": {"booking_id": str(bid)}, "payment_status": "paid", "payment_intent": "pi_x", "amount_total": 15000}}}).encode()
    t = str(int(time.time()))
    sig = hmac.new(b"whsec_testsecret123", t.encode() + b"." + payload, hashlib.sha256).hexdigest()
    urllib.request.urlopen(urllib.request.Request(WP + "/stripe", data=payload, method="POST", headers={"Stripe-Signature": f"t={t},v1={sig}", "Content-Type": "application/json"}))


wpeval('$s=get_option("jmb_settings",[]); $s["stripe_secret"]="sk_test_mock"; $s["stripe_webhook"]="whsec_testsecret123"; $s["full_amount"]="150"; $s["mini_amount"]="50"; $s["blocked"]="2026-12-15"; update_option("jmb_settings",$s);')

tomorrow = (datetime.date.today() + datetime.timedelta(days=1)).isoformat()
set_extras([
    {"date": "2026-12-08", "time": "16:00"},      # a Tuesday
    {"date": "2026-12-19", "time": "sunset"},     # weekend that will be over the cap
    {"date": "2026-12-06", "time": "10:00"},      # Sunday of a weekend that will be booked
    {"date": "2026-12-15", "time": "09:00"},      # closed date
    {"date": "2026-12-09", "time": "08:30"},      # same as a regular Wednesday slot
    {"date": "2026-12-09", "time": "13:00"},      # extra afternoon on that Wednesday
    {"date": "2027-08-01", "time": "10:00"},      # past the 180-day window
    {"date": tomorrow, "time": "10:00"},          # inside the 2-day notice
    {"date": "2020-01-01", "time": "10:00"},      # in the past (dropped)
    {"date": "2026-02-31", "time": "10:00"},      # impossible date (dropped)
])

raw, j = avail("2026-12")
check("E0 no PHP warnings/notices in the API output", not any(w in raw for w in ("Warning", "Notice", "Deprecated")), raw[:200])
check("E1 Tuesday Dec 8 extra 4:00 PM appears", slots("2026-12", "2026-12-08") == [("full", "16:00", "Extra date.")], slots("2026-12", "2026-12-08"))
check("E2 closed date Dec 15 stays closed", slots("2026-12", "2026-12-15") == [])
w = slots("2026-12", "2026-12-09")
check("E3 Wednesday: regular 8:30 + 9:00 kept, 8:30 not doubled, 1:00 PM added", [s[1] for s in w] == ["08:30", "09:00", "13:00"], w)
d = slots("2026-12", "2026-12-06")
check("E4 Sunday Dec 6 shows regular sunset slot + extra 10:00 AM", [s[1] for s in d] == ["10:00", "15:30"], d)
check("E5 extra inside the notice window is hidden", tomorrow not in j["days"] or j["days"].get(tomorrow) == [])
_, aug = avail("2027-08")
check("E6 extra beyond 180 days shows and calendar can page to it", [s["start"] for s in aug["days"]["2027-08-01"]] == ["10:00"] and j["max"] == "2027-08-01", (aug["days"]["2027-08-01"], j["max"]))
stored = json.loads(wpeval("echo wp_json_encode(jmb_extras());"))
check("E7 past + impossible dates dropped from the list", not any(e["date"] in ("2020-01-01", "2026-02-31") for e in stored), stored)

# book Dec 5 + Dec 12 -> weekend cap reached; extra on Sunday Dec 6 and on Dec 19 must still be bookable
for i, day in enumerate(["2026-12-05", "2026-12-12"]):
    code, r = book(day, "15:30", f"cap{i}@example.com", f"10.3.0.{i}")
    pay(int(db("SELECT MAX(id) FROM wp_jmb_bookings")[0][0]))
check("E8 cap reached: regular Dec 19/20 closed", slots("2026-12", "2026-12-20") == [])
d19 = slots("2026-12", "2026-12-19")
check("E9 ...but the extra golden-hour Dec 19 is open", len(d19) == 1 and d19[0][2].startswith("Golden hour session (extra"), d19)
d6 = slots("2026-12", "2026-12-06")
check("E10 extra Sunday Dec 6 open although Sat Dec 5 is booked (regular slot gone)", d6 == [("full", "10:00", "Extra date.")], d6)
code, r = book("2026-12-08", "16:00", "tue@example.com", "10.3.0.9")
check("E11 extra Tuesday can be booked", code == 200 and "checkout_url" in r, (code, r))
pay(int(db("SELECT MAX(id) FROM wp_jmb_bookings")[0][0]))
check("E12 after it is paid the Tuesday closes (no weekday mini)", slots("2026-12", "2026-12-08") == [])
code, r = book("2026-12-08", "16:00", "tue2@example.com", "10.3.0.10")
check("E13 booking the same extra time again -> 409", code == 409, (code, r))
print(f"\n{ok} passed, {bad} failed")
raise SystemExit(1 if bad else 0)
