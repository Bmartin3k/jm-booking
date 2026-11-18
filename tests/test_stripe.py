"""End-to-end tests for jm-booking 2.0 (Stripe) against the throwaway WordPress + mock Stripe."""
import hashlib, hmac, json, subprocess, time, urllib.error, urllib.parse, urllib.request

WP = "http://127.0.0.1:18080/?rest_route=/jmb/v1"
MOCK = "http://127.0.0.1:18081"
WHSEC = "whsec_testsecret123"
ok_count = fail_count = 0


def check(name, cond, extra=""):
    global ok_count, fail_count
    if cond: ok_count += 1
    else: fail_count += 1
    print(("PASS " if cond else "FAIL ") + name + (f"   [{extra}]" if extra and not cond else ""))


def req(method, path, body=None, headers=None, raw=None, xff="10.1.0.1"):
    url = WP + path if path.startswith("/") else path
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    h = {"Content-Type": "application/json", "X-Forwarded-For": xff}
    h.update(headers or {})
    r = urllib.request.Request(url, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(r) as resp: return resp.status, json.loads(resp.read() or b"null")
    except urllib.error.HTTPError as e: return e.code, json.loads(e.read() or b"null")


def db(sql):
    out = subprocess.run(["docker", "exec", "jmbtest-db", "mariadb", "-uwp", "-pwp", "wp", "-N", "-e", sql], capture_output=True, text=True).stdout
    return [line.split("\t") for line in out.strip().splitlines()]


def wpeval(code):
    return subprocess.run([str(__import__("pathlib").Path(__file__).with_name("wpcli.sh")), "eval", code], capture_output=True, text=True).stdout


def mock_sessions():
    with urllib.request.urlopen(MOCK + "/sessions.json") as r: return json.loads(r.read())


def webhook(event_type, session_id, booking_id, paid=True, secret=WHSEC, amount=15000):
    ev = {"id": "evt_test", "type": event_type, "data": {"object": {
        "id": session_id, "object": "checkout.session", "client_reference_id": str(booking_id),
        "metadata": {"booking_id": str(booking_id)}, "payment_status": "paid" if paid else "unpaid",
        "payment_intent": "pi_test_" + session_id[-5:], "amount_total": amount}}}
    payload = json.dumps(ev).encode()
    t = str(int(time.time()))
    sig = hmac.new(secret.encode(), (t + ".").encode() + payload, hashlib.sha256).hexdigest()
    return req("POST", "/stripe", raw=payload, headers={"Stripe-Signature": f"t={t},v1={sig}"})


def book(date, start, email, kind="full", xff="10.1.0.1", location="Mayfield Park", return_url="http://127.0.0.1:18080/book/"):
    return req("POST", "/book", {"kind": kind, "date": date, "start": start, "name": "Test " + email.split("@")[0],
                                 "email": email, "location": location, "return_url": return_url}, xff=xff)


def ref_of(bid): return db(f"SELECT token FROM wp_jmb_bookings WHERE id={bid}")[0][0]
def status_of(bid): return db(f"SELECT status FROM wp_jmb_bookings WHERE id={bid}")[0][0]
def last_id(): return int(db("SELECT MAX(id) FROM wp_jmb_bookings")[0][0])


# S1: no Stripe keys yet -> online booking off
code, j = book("2026-12-05", "15:30", "a@example.com")
check("S1 no Stripe keys -> 503 'not available'", code == 503, j)
code, j = req("GET", "/availability&month=2026-12")
check("S1b availability says online=false", j.get("online") is False)

wpeval('$s=get_option("jmb_settings",[]); $s["stripe_secret"]="sk_test_mock"; $s["stripe_webhook"]="' + WHSEC + '"; $s["full_amount"]="150.00"; $s["mini_amount"]="50.00"; update_option("jmb_settings",$s);')

# S2: book -> Stripe checkout created with the right amount, metadata and return URLs
code, j = book("2026-12-05", "15:30", "ann@example.com", location="Our backyard 12 Elm St")
check("S2 book returns checkout_url", code == 200 and j.get("checkout_url", "").startswith(MOCK + "/pay/"), j)
b1 = last_id()
sid1 = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={b1}")[0][0]
p = mock_sessions()[sid1]["params"]
check("S2b amount 15000 usd, metadata booking_id, client_reference_id", p.get("line_items[0][price_data][unit_amount]") == "15000"
      and p.get("line_items[0][price_data][currency]") == "usd" and p.get("metadata[booking_id]") == str(b1) and p.get("client_reference_id") == str(b1), p)
check("S2c success/cancel URLs carry the token, on our own page", p["success_url"] == f"http://127.0.0.1:18080/book/?jmb=paid&jmb_ref={ref_of(b1)}"
      and p["cancel_url"].startswith("http://127.0.0.1:18080/book/?jmb=cancel"), p.get("success_url"))
check("S2d expires_at ~30 min ahead", abs(int(p["expires_at"]) - (time.time() + 1800)) < 60)
check("S2e booking pending while paying", status_of(b1) == "pending")
code, j = req("GET", "/availability&month=2026-12")
check("S3 weekend Dec 5/6 closed while checkout open", j["days"]["2026-12-05"] == [] and j["days"]["2026-12-06"] == [])
check("S3b slots show the charge", "$150.00 retainer due now" in json.dumps(j["days"]["2026-12-12"]))

# S4: webhook security
code, j = webhook("checkout.session.completed", sid1, b1, secret="whsec_wrong")
check("S4 webhook with wrong signature -> 400", code == 400 and status_of(b1) == "pending", (code, j))
code, j = req("POST", "/stripe", raw=b'{"type":"checkout.session.completed"}', headers={"Stripe-Signature": "t=1,v1=abc"})
check("S4b webhook with stale/garbage signature -> 400", code == 400)
code, j = webhook("checkout.session.completed", "cs_test_other", b1)
check("S4c signed event for a different session is ignored", status_of(b1) == "pending", j)
code, j = webhook("checkout.session.completed", sid1, b1, paid=False)
check("S4d completed but unpaid (delayed method) does not confirm", status_of(b1) == "pending", j)

# S5: real payment -> confirmed, mini opens, status endpoint
code, j = webhook("checkout.session.completed", sid1, b1)
check("S5 signed payment webhook confirms", code == 200 and status_of(b1) == "confirmed", j)
row = db(f"SELECT payment_id, amount_cents FROM wp_jmb_bookings WHERE id={b1}")[0]
check("S5b payment id + amount stored", row[0].startswith("pi_test_") and row[1] == "15000", row)
code, j = req("GET", "/status&ref=" + ref_of(b1))
check("S5c status by token -> confirmed $150.00", j.get("status") == "confirmed" and j.get("paid") == "$150.00", j)
code, j = req("GET", "/status&ref=" + "A" * 32)
check("S5d status with unknown token -> 404", code == 404)
code, j = req("GET", "/availability&month=2026-12")
mini = j["days"]["2026-12-05"]
check("S5e mini opens on Dec 5 with private location hidden", len(mini) == 1 and mini[0]["kind"] == "mini" and mini[0]["location"] == "Shared with you after booking", mini)
code, j = webhook("checkout.session.completed", sid1, b1)
check("S6 Stripe retry of the same event is harmless", code == 200 and j.get("already") is True, j)

# S7: same email starting a second checkout releases the first
book("2026-12-12", "15:30", "bob@example.com", xff="10.1.0.2"); b2 = last_id()
sid2 = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={b2}")[0][0]
book("2026-12-19", "15:30", "bob@example.com", xff="10.1.0.2"); b3 = last_id()
check("S7 new checkout by same email cancels the old one", status_of(b2) == "cancelled" and status_of(b3) == "pending")
check("S7b old Stripe checkout expired", mock_sessions()[sid2]["status"] == "expired")

# S8: back button on Stripe -> cancel endpoint releases the time and expires the checkout
sid3 = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={b3}")[0][0]
code, j = req("POST", "/cancel", {"ref": ref_of(b3)})
check("S8 cancel by token releases the slot", status_of(b3) == "cancelled" and mock_sessions()[sid3]["status"] == "expired", j)
code, j = req("POST", "/cancel", {"ref": "B" * 32})
check("S8b cancel with unknown token -> 404", code == 404)

# S9: Stripe says the checkout expired
book("2026-12-02", "08:30", "cat@example.com", xff="10.1.0.3"); b4 = last_id()
sid4 = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={b4}")[0][0]
webhook("checkout.session.expired", sid4, b4)
check("S9 checkout.session.expired webhook -> expired", status_of(b4) == "expired")

# S10: return URL pointing at another site is replaced by ours
book("2026-12-16", "08:30", "dee@example.com", xff="10.1.0.4", return_url="https://evil.example/phish"); b5 = last_id()
sid5 = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={b5}")[0][0]
check("S10 foreign return_url ignored", mock_sessions()[sid5]["params"]["success_url"].startswith("http://127.0.0.1:18080/?jmb=paid"), mock_sessions()[sid5]["params"]["success_url"])

# S11: paid after the time was released AND taken by someone else -> 'conflict', not a double booking
book("2026-12-19", "15:30", "eve@example.com", xff="10.1.0.5"); b6 = last_id()
sid6 = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={b6}")[0][0]
db(f"UPDATE wp_jmb_bookings SET status='expired' WHERE id={b6}")
book("2026-12-19", "15:30", "fay@example.com", xff="10.1.0.6"); b7 = last_id()
webhook("checkout.session.completed", sid6, b6)
check("S11 late payment for a taken time -> conflict", status_of(b6) == "conflict" and status_of(b7) == "pending", (status_of(b6), status_of(b7)))

# S12: amount for mini; mini booking hides private spot
book("2026-12-05", "14:00", "gus@example.com", kind="mini", xff="10.1.0.7"); b8 = last_id()
sid8 = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={b8}")[0][0]
loc = db(f"SELECT location FROM wp_jmb_bookings WHERE id={b8}")[0][0]
check("S12 mini charged 5000 and private spot not copied", mock_sessions()[sid8]["params"]["line_items[0][price_data][unit_amount]"] == "5000" and loc.startswith("Same spot as booking #"), loc)

# S13: Stripe API error -> booking released, friendly error
wpeval('$s=get_option("jmb_settings"); $s["stripe_secret"]="sk_live_badkey"; update_option("jmb_settings",$s);')
code, j = book("2026-12-23", "08:30", "hal@example.com", xff="10.1.0.8"); b9 = last_id()
check("S13 Stripe error -> 502 and slot released", code == 502 and status_of(b9) == "cancelled", (code, j))

print(f"\n{ok_count} passed, {fail_count} failed")
raise SystemExit(1 if fail_count else 0)
