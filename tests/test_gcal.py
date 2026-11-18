"""Google Calendar sync tests (jm-booking 2.2): her events close times, paid bookings land in her calendar."""
import hashlib, hmac, json, pathlib, subprocess, time, urllib.error, urllib.request

WP = "http://127.0.0.1:18080/?rest_route=/jmb/v1"
MOCK = "http://127.0.0.1:18081"
WPCLI = str(pathlib.Path(__file__).with_name("wpcli.sh"))
SA_EMAIL = "booking-bot@test-project.iam.gserviceaccount.com"
ok = bad = 0


def check(name, cond, extra=""):
    global ok, bad
    ok, bad = (ok + 1, bad) if cond else (ok, bad + 1)
    print(("PASS " if cond else "FAIL ") + name + (f"   [{extra}]" if not cond else ""))


def wpeval(code):
    return subprocess.run([WPCLI, "eval", code], capture_output=True, text=True).stdout


def db(sql):
    out = subprocess.run(["docker", "exec", "jmbtest-db", "mariadb", "-uwp", "-pwp", "wp", "-N", "-e", sql], capture_output=True, text=True).stdout
    return [l.split("\t") for l in out.strip().splitlines()]


def http(method, url, body=None, headers=None):
    data = body if isinstance(body, bytes) else (json.dumps(body).encode() if body is not None else None)
    r = urllib.request.Request(url, data=data, method=method, headers={"Content-Type": "application/json", **(headers or {})})
    try:
        with urllib.request.urlopen(r) as resp: return resp.status, json.loads(resp.read() or b"null")
    except urllib.error.HTTPError as e: return e.code, json.loads(e.read() or b"null")


def clear_cache():
    db("DELETE FROM wp_options WHERE option_name LIKE '%transient%jmb_gbusy%'")


def day(d, month=None):
    month = month or d[:7]
    return [(s["kind"], s["start"]) for s in http("GET", f"{WP}/availability&month={month}")[1]["days"][d]]


def fixture(events):
    http("POST", MOCK + "/gcal/fixture", events)
    clear_cache()


def timed(eid, start, end, **kw):
    return {"id": eid, "status": "confirmed", "start": {"dateTime": start}, "end": {"dateTime": end}, **kw}


def allday(eid, start, end, **kw):
    return {"id": eid, "status": "confirmed", "start": {"date": start}, "end": {"date": end}, **kw}


def book(date, start, email, xff):
    return http("POST", WP + "/book", {"kind": "full", "date": date, "start": start, "name": "T " + email.split("@")[0], "email": email,
                                       "location": "Mayfield Park", "return_url": "http://127.0.0.1:18080/book/"}, {"X-Forwarded-For": xff})


def pay(bid):
    sid = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={bid}")[0][0]
    payload = json.dumps({"type": "checkout.session.completed", "data": {"object": {"id": sid, "client_reference_id": str(bid), "metadata": {"booking_id": str(bid)},
                          "payment_status": "paid", "payment_intent": "pi_g", "amount_total": 15000}}}).encode()
    t = str(int(time.time()))
    sig = hmac.new(b"whsec_testsecret123", t.encode() + b"." + payload, hashlib.sha256).hexdigest()
    http("POST", WP + "/stripe", payload, {"Stripe-Signature": f"t={t},v1={sig}"})


def admin_post(action, nonce_action, fields):
    """Run an admin-post handler as the admin, the way the settings form would; return the redirect message."""
    php = ("wp_set_current_user(1); $_POST = wp_slash(json_decode(base64_decode('%s'), true)); $_POST['_wpnonce'] = wp_create_nonce('%s');"
           "$_REQUEST = $_POST; add_filter('wp_redirect', function ($l) { echo urldecode($l), \"\\n\"; exit; }); do_action('admin_post_%s');") % (
        __import__("base64").b64encode(json.dumps(fields).encode()).decode(), nonce_action, action)
    return wpeval(php)


# ---------------------------------------------------------------- setup
pem = wpeval('$k = openssl_pkey_new(["private_key_bits" => 2048]); openssl_pkey_export($k, $pem); echo $pem;')
sa = {"type": "service_account", "client_email": SA_EMAIL, "private_key": pem, "private_key_id": "k1", "token_uri": "http://stripe-mock:8000/token"}
wpeval('$s=get_option("jmb_settings",[]); $s["stripe_secret"]="sk_test_mock"; $s["stripe_webhook"]="whsec_testsecret123"; $s["full_amount"]="150"; $s["mini_amount"]="50"; update_option("jmb_settings",$s);')

check("G0 before connecting, Sat Dec 12 is open", day("2026-12-12") == [("full", "15:30")])
out = admin_post("jmb_save", "jmb_save", {"jmb": {"gcal_json": "{not json", "gcal_id": "jess@example.com"}})
check("G0b a broken key is refused and nothing is saved", "not a valid service account key" in out and "gcal_id\";s:16" not in wpeval('echo serialize(get_option("jmb_settings"));'), out)
out = admin_post("jmb_save", "jmb_save", {"jmb": {"gcal_json": json.dumps(sa), "gcal_id": "jess@example.com", "gcal_mode": "weekend_day", "gcal_buffer": "30", "gcal_push": "1",
                                                   "full_amount": "150", "mini_amount": "50", "school_days": ["3"], "summer_days": ["3"], "weekend_minis": "1",
                                                   "locations": "Mayfield Park\nRed Bud Isle"}})
check("G0c a real key is saved through the settings form", "saved=1" in out and SA_EMAIL in wpeval('echo jmb_settings()["gcal_json"];'), out)
out = admin_post("jmb_testgcal", "jmb_testgcal", {})
check("G0d 'Check Google Calendar' reports read + write OK", "connected: reads OK" in out and "can add events" in out, out)
claims = http("GET", MOCK + "/gcal.json")[1]["tokens"][-1]
check("G0e Google sign-in JWT has the right issuer, scope and audience", claims["iss"] == SA_EMAIL and claims["scope"].endswith("/auth/calendar.events")
      and claims["aud"] == "http://stripe-mock:8000/token" and claims["exp"] - claims["iat"] == 3600, claims)
check("G0f the connection-test event was deleted again", http("GET", MOCK + "/gcal.json")[1]["created"] == {})

# ------------------------------------------------------- her events close times
fixture([
    allday("a1", "2026-12-12", "2026-12-13"),                                         # Sat all day
    timed("t1", "2026-12-19T10:00:00-06:00", "2026-12-19T11:00:00-06:00"),             # Sat morning
    timed("t2", "2026-12-16T10:30:00-06:00", "2026-12-16T11:30:00-06:00"),             # Wed clash with both slots
    timed("t3", "2026-12-23T13:00:00-06:00", "2026-12-23T14:00:00-06:00"),             # Wed afternoon, no clash
    timed("t4", "2026-12-30T11:15:00-06:00", "2026-12-30T12:00:00-06:00"),             # Wed, clashes only with 9:00 (buffer)
    timed("f1", "2026-12-26T09:00:00-06:00", "2026-12-26T10:00:00-06:00", transparency="transparent"),  # marked Free
    timed("c1", "2026-12-27T09:00:00-06:00", "2026-12-27T10:00:00-06:00", status="cancelled"),
    allday("a2", "2027-01-02", "2027-01-05"),                                         # Sat-Mon trip (end exclusive)
])
check("G1 all-day event closes Sat Dec 12, Sun Dec 13 stays open", day("2026-12-12") == [] and day("2026-12-13") == [("full", "15:30")])
check("G2 a 10-11 AM event closes the whole Saturday (weekend rule)", day("2026-12-19") == [] and day("2026-12-20") == [("full", "15:30")])
check("G3 Wednesday event 10:30-11:30 removes both morning slots", day("2026-12-16") == [], day("2026-12-16"))
check("G4 Wednesday afternoon event leaves the morning open", day("2026-12-23") == [("full", "08:30"), ("full", "09:00")], day("2026-12-23"))
check("G5 11:15 event + 30 min buffer removes only the 9:00 slot", day("2026-12-30") == [("full", "08:30")], day("2026-12-30"))
check("G6 'Free' and cancelled events are ignored", day("2026-12-26") == [("full", "15:30")] and day("2026-12-27") == [("full", "15:30")])
check("G7 multi-day trip closes Sat Jan 2 and Sun Jan 3", day("2027-01-02") == [] and day("2027-01-03") == [] and day("2027-01-06") == [("full", "08:30"), ("full", "09:00")])

# ------------------------------------------------ paid booking goes to her calendar
code, r = book("2026-12-05", "15:30", "ann@example.com", "10.5.0.1")
b1 = int(db("SELECT MAX(id) FROM wp_jmb_bookings")[0][0])
pay(b1)
created = http("GET", MOCK + "/gcal.json")[1]["created"]
ev = next(iter(created.values()), {})
check("G8 paid booking added to her calendar", ev.get("summary") == "Full session: T ann" and ev.get("start") == {"dateTime": "2026-12-05T15:30:00", "timeZone": "America/Chicago"}
      and ev.get("end", {}).get("dateTime") == "2026-12-05T17:30:00" and ev.get("location") == "Mayfield Park"
      and ev.get("extendedProperties", {}).get("private", {}).get("jmb_booking") == str(b1) and "ann@example.com" in ev.get("description", ""), ev)
row = db(f"SELECT gcal_event, gcal_status FROM wp_jmb_bookings WHERE id={b1}")[0]
check("G8b event id stored on the booking", row[0] == ev.get("id") and row[1].startswith("added"), row)
clear_cache()
check("G9 her calendar now has the session, but the mini that day stays open", day("2026-12-05") == [("mini", "14:00")], day("2026-12-05"))

# ------------------------------------------------ booking re-checks her calendar live
check("G10a Wed Dec 9 open (and cached)", day("2026-12-09") == [("full", "08:30"), ("full", "09:00")])
http("POST", MOCK + "/gcal/fixture", http("GET", MOCK + "/gcal.json")[1]["fixture"] + [timed("t9", "2026-12-09T08:00:00-06:00", "2026-12-09T12:00:00-06:00")])
code, r = book("2026-12-09", "08:30", "late@example.com", "10.5.0.2")
check("G10 she adds an event, then someone tries to book it: refused", code == 409, (code, r))

# ------------------------------------------------ cancelling removes the event
out = wpeval(f"wp_set_current_user(1); $_GET = ['id' => {b1}, 'do' => 'cancel', '_wpnonce' => wp_create_nonce('jmb_act_{b1}')]; $_REQUEST = $_GET;"
             "add_filter('wp_redirect', function ($l) { echo urldecode($l); exit; }); do_action('admin_post_jmb_act');")
created = http("GET", MOCK + "/gcal.json")[1]["created"]
check("G11 cancelling the booking removes it from her calendar", created == {} and db(f"SELECT COUNT(*) FROM wp_jmb_bookings WHERE id={b1} AND gcal_event='' AND status='cancelled'") == [["1"]], (out, created))

# ------------------------------------------------ Google outage keeps the last known busy days
http("POST", MOCK + "/gcal/fail", {"on": True})
clear_cache()
check("G12 Google down: Sat Dec 12 stays closed from the last good copy", day("2026-12-12") == [])
check("G12b the problem shows in the settings status", "Could not read" in wpeval('echo get_option("jmb_gcal_status")["msg"];'))
http("POST", MOCK + "/gcal/fail", {"on": False})

# ------------------------------------------------ other modes
wpeval('$s=get_option("jmb_settings"); $s["gcal_mode"]="overlap"; update_option("jmb_settings",$s);'); clear_cache()
check("G13 'only clashing times' mode: Sat Dec 19 sunset slot opens despite the morning event", day("2026-12-19") == [("full", "15:30")], day("2026-12-19"))
wpeval('$s=get_option("jmb_settings"); $s["gcal_mode"]="day"; update_option("jmb_settings",$s);'); clear_cache()
check("G14 'any event closes the day' mode: Wed Dec 23 closes", day("2026-12-23") == [], day("2026-12-23"))

out = wpeval("$e = []; set_error_handler(function ($n, $m, $f, $l) use (&$e) { $e[] = basename($f) . ':' . $l . ' ' . $m; return true; }, E_ALL);"
             "wp_set_current_user(1); ob_start(); jmb_admin_settings(); jmb_admin_bookings(); $h = ob_get_clean();"
             "echo count($e) ? implode(' | ', $e) : 'clean', ' / ', strpos($h, 'Share the calendar with') !== false ? 'has-share-line' : 'no-share-line';")
check("G15 admin pages render with no PHP warnings and show the share address", out.strip() == "clean / has-share-line", out)

print(f"\n{ok} passed, {bad} failed")
raise SystemExit(1 if bad else 0)
