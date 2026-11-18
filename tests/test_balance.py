"""Balance payment tests (jm-booking 2.5): planning, invoices, pay page + Stripe, receipts, reminders, overdue, admin actions.
Dates are relative to today."""
import base64, datetime, hashlib, hmac, html as html_mod, http.cookiejar, json, pathlib, re, subprocess, time, urllib.error, urllib.parse, urllib.request

BASE = "http://127.0.0.1:18080"
WP = BASE + "/?rest_route=/jmb/v1"
MOCK = "http://127.0.0.1:18081"
WPCLI = str(pathlib.Path(__file__).with_name("wpcli.sh"))
ok = bad = 0


def check(name, cond, extra=""):
    global ok, bad
    ok, bad = (ok + 1, bad) if cond else (ok, bad + 1)
    print(("PASS " if cond else "FAIL ") + name + (f"   [{str(extra)[:300]}]" if not cond else ""))


def wpeval(code):
    return subprocess.run([WPCLI, "eval", code], capture_output=True, text=True).stdout


def wpjson(code):
    out = wpeval("echo wp_json_encode(" + code + ");")
    return json.loads(out) if out.strip() else None


def db(sql):
    out = subprocess.run(["docker", "exec", "jmbtest-db", "mariadb", "-uwp", "-pwp", "wp", "-N", "-e", sql], capture_output=True, text=True).stdout
    return [l.split("\t") for l in out.rstrip("\n").split("\n") if l != ""]


def settings(**kw):
    wpeval("$s = get_option('jmb_settings', []); $n = json_decode(base64_decode('%s'), true); update_option('jmb_settings', array_merge($s, $n));"
           % base64.b64encode(json.dumps(kw).encode()).decode())


def day(n):
    return (datetime.date.today() + datetime.timedelta(days=n)).isoformat()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None


def http(method, url, body=None, headers=None, raw=False, follow=True):
    data = body if isinstance(body, bytes) else (json.dumps(body).encode() if body is not None else None)
    r = urllib.request.Request(url, data=data, method=method, headers={"Content-Type": "application/json", **(headers or {})})
    op = urllib.request.build_opener() if follow else urllib.request.build_opener(NoRedirect)
    try:
        with op.open(r) as resp:
            c = resp.read(); return resp.status, (c if raw else json.loads(c or b"null")), dict(resp.headers)
    except urllib.error.HTTPError as e:
        c = e.read()
        try: return e.code, (c if raw else json.loads(c or b"null")), dict(e.headers)
        except ValueError: return e.code, c, dict(e.headers)


def signed(event):
    payload = json.dumps(event).encode()
    t = str(int(time.time()))
    sig = hmac.new(b"whsec_testsecret123", t.encode() + b"." + payload, hashlib.sha256).hexdigest()
    return http("POST", WP + "/stripe", payload, {"Stripe-Signature": f"t={t},v1={sig}"})


def completed(session_id, metadata, amount, ref=""):
    return signed({"type": "checkout.session.completed", "data": {"object": {"id": session_id, "client_reference_id": ref, "metadata": metadata,
                   "payment_status": "paid", "payment_intent": "pi_" + session_id[-6:], "amount_total": amount}}})


def book_and_pay(date, email, start="10:00", xff="10.7.0.1"):
    code, j, _ = http("POST", WP + "/book", {"kind": "full", "date": date, "start": start, "name": "T " + email.split("@")[0], "email": email,
                                              "location": "Mayfield Park", "return_url": BASE + "/book/"}, {"X-Forwarded-For": xff})
    assert code == 200, (code, j)
    bid = int(db("SELECT MAX(id) FROM wp_jmb_bookings")[0][0])
    sid = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={bid}")[0][0]
    completed(sid, {"booking_id": str(bid)}, 15000, str(bid))
    return bid


def pays(bid):
    return wpjson(f"jmb_payments_for({bid})") or []


def mails():
    return wpjson("get_option('jmb_test_mail', [])") or []


def run():
    return wpjson("jmb_run_payments()")


def admin_act(pid, do, amount=None):
    extra = f", 'amount' => '{amount}'" if amount else ""
    return wpeval(f"wp_set_current_user(1); $_GET = ['pid' => {pid}, 'do' => '{do}', '_wpnonce' => wp_create_nonce('jmb_pay_act_{pid}'){extra}]; $_REQUEST = $_GET;"
                  "add_filter('wp_redirect', function ($l) { echo urldecode($l); exit; }); do_action('admin_post_jmb_pay_act');")


# ---------------------------------------------------------------- setup
settings(stripe_secret="sk_test_mock", stripe_webhook="whsec_testsecret123", full_amount="150.00", mini_amount="50.00",
         full_price="$350", mini_price="$150", business_name="Test Studio", pay_on=0, pay_parts=1, pay_due_days=2, pay_send_days=7, pay_remind=1)
wpeval("update_option('jmb_extra', " + "[" + ",".join(f"['date' => '{day(n)}', 'time' => '10:00']" for n in (5, 6, 12, 40, 90)) + "], false);")

b0 = book_and_pay(day(6), "off@example.com")
check("P0 balance invoicing off: a paid booking gets no balance", pays(b0) == [])

settings(pay_on=1)
wpeval("update_option('jmb_pay_since', time() - 60, false);")
b1 = book_and_pay(day(5), "ann@example.com", xff="10.7.0.2")
p1 = pays(b1)
check("P1 paid booking gets a balance: $350 - $150 retainer = $200, due 2 days before", len(p1) == 1 and p1[0]["amount_cents"] == "20000"
      and p1[0]["due_date"] == day(3) and p1[0]["status"] == "open" and len(p1[0]["token"]) == 32, p1)

# ---------------------------------------------------------------- planning
def plan(date, parts, retainer=15000, kind="full"):
    settings(pay_parts=parts)
    return wpjson(f"jmb_balance_plan(['kind' => '{kind}', 'date' => '{date}', 'amount_cents' => {retainer}], jmb_settings())")

check("P2 3 monthly payments for a session 90 days out: $66.68 + $66.66 + $66.66, 30 days apart, last 2 days before",
      plan(day(90), 3) == [[6668, day(28)], [6666, day(58)], [6666, day(88)]], plan(day(90), 3))
check("P3 not enough time: a session in 5 days gets one payment even with 3 asked for", plan(day(5), 3) == [[20000, day(3)]])
check("P3b session in 40 days with 4 asked for: 2 payments", [x[1] for x in plan(day(40), 4)] == [day(8), day(38)])
check("P4 session tomorrow: due today", plan(day(1), 1) == [[20000, day(0)]])
check("P5 nothing to collect when the retainer covers the price", plan(day(30), 1, retainer=35000) == [])
settings(full_price="$350+")
check("P5b no balance when the price is not a plain amount", plan(day(30), 1) == [])
settings(full_price="$350", pay_parts=1)
db(f"INSERT INTO wp_jmb_bookings (kind,date,start_time,end_time,status,name,email,location,source,created_at,confirmed_at,amount_cents) VALUES "
   f"('full','{day(30)}','10:00','12:00','confirmed','Old Client','old@example.com','Mayfield Park','web',UTC_TIMESTAMP() - INTERVAL 2 DAY, UTC_TIMESTAMP() - INTERVAL 2 DAY,15000)")
bold = int(db("SELECT MAX(id) FROM wp_jmb_bookings")[0][0])
check("P6 bookings paid before the switch was turned on are never billed", wpeval(f"echo jmb_balance_create(jmb_get({bold}));") == "0" and pays(bold) == [])

# ---------------------------------------------------------------- invoice email
wpeval("delete_option('jmb_test_mail');")
r1 = run()
inv = [m for m in mails() if m["to"] == "ann@example.com"]
check("P7 invoice emailed (due in 3 days, so already inside the 7-day window) with amount, due date and pay link",
      r1["invoices"] >= 1 and len(inv) == 1 and "$200.00" in inv[0]["subject"] and f"jmb_pay={p1[0]['token']}" in inv[0]["message"]
      and "Hi T," in inv[0]["message"] and "{" not in inv[0]["message"], (r1, inv))
r2 = run()
check("P7b running again sends nothing twice (and no reminder before the due date)", r2 == {"invoices": 0, "reminders": 0, "overdue": 0}, r2)

# ---------------------------------------------------------------- pay page + Stripe
url = f"{BASE}/?jmb_pay={p1[0]['token']}"
code, page, hdr = http("GET", url, raw=True)
page = page.decode()
check("P8 pay page shows what is due and a pay button, not indexed", code == 200 and "$200.00" in page and "Pay $200.00 by card" in page
      and "noindex" in hdr.get("X-Robots-Tag", ""), code)
code, _, hdr = http("POST", url, b"", {"Content-Type": "application/x-www-form-urlencoded"}, raw=True, follow=False)
sid = db(f"SELECT stripe_session FROM wp_jmb_payments WHERE id={p1[0]['id']}")[0][0]
sess = http("GET", MOCK + "/sessions.json")[1].get(sid, {}).get("params", {})
check("P8b paying opens Stripe Checkout for exactly the balance, tagged with the payment",
      code == 303 and hdr.get("Location", "").startswith(MOCK + "/pay/") and sess.get("line_items[0][price_data][unit_amount]") == "20000"
      and sess.get("metadata[jmb_payment]") == p1[0]["id"] and sess.get("success_url") == url + "&paid=1", (code, sess))
code, page, _ = http("GET", url + "&paid=1", raw=True)
check("P8c back from Stripe before the webhook: 'being confirmed', page refreshes itself", "being confirmed" in page.decode() and 'http-equiv="refresh"' in page.decode())

code, j, _ = completed("cs_test_wrong", {"jmb_payment": p1[0]["id"]}, 20000)
check("P9 a payment event for a different checkout is ignored", pays(b1)[0]["status"] == "open" and j.get("ignored"), j)
wpeval("delete_option('jmb_test_mail');")
code, j, _ = completed(sid, {"jmb_payment": p1[0]["id"], "jmb_booking": str(b1)}, 20000, "pay-" + p1[0]["id"])
p1 = pays(b1)
rcpt = [m for m in mails() if m["to"] == "ann@example.com"]
check("P9b Stripe payment marks it paid and sends the receipt with the totals",
      p1[0]["status"] == "paid" and p1[0]["payment_id"].startswith("pi_") and len(rcpt) == 1 and "$200.00" in rcpt[0]["message"]
      and "Paid so far: $350.00 of $350.00. Still to pay: $0.00." in rcpt[0]["message"], (p1, rcpt))
check("P9c she is told too", any("Balance paid" in m["subject"] for m in mails()))
check("P9d the booking itself is untouched (still confirmed, retainer kept)", db(f"SELECT status, amount_cents FROM wp_jmb_bookings WHERE id={b1}") == [["confirmed", "15000"]])
code, j, _ = completed(sid, {"jmb_payment": p1[0]["id"]}, 20000)
check("P9e Stripe retrying the event is harmless", j.get("already") is True and len([m for m in mails() if m["to"] == "ann@example.com"]) == 1)
check("P9f pay page now says paid", "Paid, thank you!" in http("GET", url, raw=True)[1].decode())

# ---------------------------------------------------------------- reminder + overdue
b2 = book_and_pay(day(12), "ben@example.com", xff="10.7.0.3")
p2 = pays(b2)[0]
db(f"UPDATE wp_jmb_payments SET due_date='{day(0)}', invoice_sent_at=UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE id={p2['id']}")
wpeval("delete_option('jmb_test_mail');")
run(); run()
rem = [m for m in mails() if m["to"] == "ben@example.com"]
check("P10 unpaid on the due date: one reminder", len(rem) == 1 and "due today" in rem[0]["subject"], rem)
db(f"UPDATE wp_jmb_payments SET due_date='{day(-1)}' WHERE id={p2['id']}")
wpeval("delete_option('jmb_test_mail');")
run(); run()
over = [m for m in mails() if "overdue" in m["subject"].lower()]
check("P11 the day after: she gets one overdue email with the client's payment link", len(over) == 1 and "T ben" in over[0]["subject"] and "jmb_pay=" in over[0]["message"], over)

jar = __import__("http.cookiejar").cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
op.open(BASE + "/wp-login.php")
op.open(BASE + "/wp-login.php", urllib.parse.urlencode({"log": "admin", "pwd": "admin", "wp-submit": "Log In", "testcookie": "1"}).encode())
lst = op.open(BASE + "/wp-admin/admin.php?page=jmb&show=all").read().decode()
check("P11b wp-admin banner + the list shows the balance as overdue with actions",
      "1 balance payment is overdue" in lst and "balance $200.00 due" in lst and "overdue</strong>" in lst and "change amount" in lst and "balance $200.00 ✓" in lst and "retainer $150.00 ✓" in lst)

# ---------------------------------------------------------------- admin actions
out = admin_act(p2["id"], "amount", "245.50")
check("P12 change amount (e.g. prints added)", "changed to $245.50" in out and pays(b2)[0]["amount_cents"] == "24550", out)
wpeval("delete_option('jmb_test_mail');")
out = admin_act(p2["id"], "send")
check("P12b send now (invoice already sent, so it sends the reminder text with the new amount)", "reminder sent" in out and "$245.50" in mails()[-1]["message"], out)
out = admin_act(p2["id"], "paid")
check("P12c mark paid by hand (cash/Venmo)", pays(b2)[0]["status"] == "paid" and pays(b2)[0]["paid_note"] == "marked paid by hand", out)
check("P12d actions refuse to touch a paid payment", "already paid" in admin_act(p2["id"], "void"))

b3 = book_and_pay(day(40), "cat@example.com", xff="10.7.0.4")
p3 = pays(b3)[0]
wpeval(f"wp_set_current_user(1); $_GET = ['id' => {b3}, 'do' => 'cancel', '_wpnonce' => wp_create_nonce('jmb_act_{b3}')]; $_REQUEST = $_GET;"
       "add_filter('wp_redirect', function ($l) { exit; }); do_action('admin_post_jmb_act');")
check("P13 cancelling the booking voids its open balance", pays(b3)[0]["status"] == "void")
check("P13b its pay link now says nothing is due", "Nothing to pay" in http("GET", f"{BASE}/?jmb_pay={p3['token']}", raw=True)[1].decode())
code, _, _ = http("GET", f"{BASE}/?jmb_pay={'Z' * 32}", raw=True)
check("P14 unknown pay link -> 404", code == 404)

# ---------------------------------------------------------------- contract fields + settings page + test email
settings(contract_on=1, contract_full="# Agreement\n\nRetainer {retainer}. Balance {balance}, due {balance_due}.")
code, prev, _ = http("POST", WP + "/contract", {"kind": "full", "date": day(90), "start": "10:00", "name": "Dee Client", "email": "dee@example.com", "location": "Mayfield Park"})
check("P15 contracts can state the balance and when it is due", "Balance $200.00, due 2 days before the session." in json.dumps(prev), prev)
settings(contract_on=0)

settings(full_price="$350+")
st = op.open(BASE + "/wp-admin/admin.php?page=jmb-settings").read().decode()
check("P16 settings warn when the price is not a plain amount", "is not a plain amount" in st)
settings(full_price="$350")
st = op.open(BASE + "/wp-admin/admin.php?page=jmb-settings").read().decode()
warn = re.findall(r"(?:<b>)?(?:Warning|Notice|Deprecated|Fatal error)(?:</b>)?:\s.{0,160}", st + lst)
check("P16b settings show the worked-out balance; no PHP warnings", "= <strong>$200.00</strong>" in st and not warn, warn)

wpeval("$s = get_option('jmb_settings'); $s['pay_on'] = 0; update_option('jmb_settings', $s); delete_option('jmb_pay_since');")
out = wpeval("wp_set_current_user(1); $_POST = wp_slash(['jmb' => ['pay_on' => '1', 'pay_parts' => '2', 'pay_due_days' => '3', 'pay_send_days' => '5', 'pay_remind' => '1', 'full_price' => '$350', 'full_amount' => '150'],"
             " 'jmb_testpay' => 'invoice', '_wpnonce' => wp_create_nonce('jmb_save')]); $_REQUEST = $_POST; delete_option('jmb_test_mail');"
             "add_filter('wp_redirect', function ($l) { echo urldecode($l); exit; }); do_action('admin_post_jmb_save');")
since = int(wpeval("echo (int) get_option('jmb_pay_since');") or 0)
t = mails()[-1] if mails() else {}
check("P17 switching it on in settings records 'from now on'; 'Save and send me a test' sends a [Test] invoice",
      abs(since - time.time()) < 120 and t.get("subject", "").startswith("[Test] Your balance") and "$200.00" in t.get("message", "") and "invoice test sent" in out.lower(), (since, t.get("subject"), out))

db(f"UPDATE wp_jmb_bookings SET date='2020-01-04' WHERE id={b1}")
wpeval("do_action('jmb_purge_old');")
check("P18 the yearly clean-up also removes the deleted booking's payments", db(f"SELECT COUNT(*) FROM wp_jmb_payments WHERE booking_id={b1}") == [["0"]])

print(f"\n{ok} passed, {bad} failed")
raise SystemExit(1 if bad else 0)
