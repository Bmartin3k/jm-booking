"""Security tests (attacker's view): injection, escaping, forged webhooks, double payments, other people's checkouts,
access control, secrets on pages, request amplification, framing. Dates are relative to today."""
import base64, datetime, hashlib, hmac, json, pathlib, re, subprocess, time, urllib.error, urllib.parse, urllib.request
from http.cookiejar import CookieJar

BASE = "http://127.0.0.1:18080"
WP = BASE + "/?rest_route=/jmb/v1"
MOCK = "http://127.0.0.1:18081"
WPCLI = str(pathlib.Path(__file__).with_name("wpcli.sh"))
XSS = '<img src=x onerror=alert(1)>"\'><script>alert(2)</script>'
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


def http(method, url, body=None, headers=None, raw=False, follow=True, opener=None):
    data = body if isinstance(body, bytes) else (json.dumps(body).encode() if body is not None else None)
    r = urllib.request.Request(url, data=data, method=method, headers={"Content-Type": "application/json", **(headers or {})})
    op = opener or (urllib.request.build_opener() if follow else urllib.request.build_opener(NoRedirect))
    try:
        with op.open(r) as resp:
            c = resp.read(); return resp.status, (c if raw else json.loads(c or b"null")), dict(resp.headers)
    except urllib.error.HTTPError as e:
        c = e.read()
        try: return e.code, (c if raw else json.loads(c or b"null")), dict(e.headers)
        except ValueError: return e.code, c, dict(e.headers)


def signed(event, secret=b"whsec_testsecret123", t=None):
    payload = json.dumps(event).encode()
    t = str(t or int(time.time()))
    sig = hmac.new(secret, t.encode() + b"." + payload, hashlib.sha256).hexdigest()
    return http("POST", WP + "/stripe", payload, {"Stripe-Signature": f"t={t},v1={sig}"})


def completed(sid, metadata, amount, ref="", pi=None):
    return signed({"type": "checkout.session.completed", "data": {"object": {"id": sid, "client_reference_id": ref, "metadata": metadata,
                   "payment_status": "paid", "payment_intent": pi or "pi_" + sid[-6:], "amount_total": amount}}})


def book(date, email, xff, **extra):
    body = {"kind": "full", "date": date, "start": "10:00", "name": "T " + email.split("@")[0], "email": email, "location": "Mayfield Park",
            "return_url": BASE + "/book/", "client_key": "K" * 32}
    body.update(extra)
    code, j, _ = http("POST", WP + "/book", body, {"X-Forwarded-For": xff})
    return code, j, int(db("SELECT IFNULL(MAX(id),0) FROM wp_jmb_bookings")[0][0])


def mails():
    return wpjson("get_option('jmb_test_mail', [])") or []


def mock_sessions():
    return http("GET", MOCK + "/sessions.json")[1]


def login(user, pw):
    jar = CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    op.open(BASE + "/wp-login.php")
    op.open(BASE + "/wp-login.php", urllib.parse.urlencode({"log": user, "pwd": pw, "wp-submit": "Log In", "testcookie": "1"}).encode())
    return op


# ---------------------------------------------------------------- setup
pem = wpeval('$k = openssl_pkey_new(["private_key_bits" => 2048]); openssl_pkey_export($k, $pem); echo $pem;')
sa = json.dumps({"type": "service_account", "client_email": "bot@test.iam.gserviceaccount.com", "private_key": pem, "token_uri": "http://stripe-mock:8000/token"})
settings(stripe_secret="sk_test_mock", stripe_webhook="whsec_testsecret123", full_amount="150.00", mini_amount="50.00", full_price="$350", mini_price="$150",
         pay_on=1, pay_parts=1, contract_on=1, contract_full="# Agreement\n\nBetween {business_name} and {client_name} at {location}. Notes are not in contracts.",
         turnstile_secret="0x4AAAAAAATurnstileSecretValue", gcal_json=sa, gcal_id="jess@example.com", zapier_hook="")
settings(turnstile_secret="")   # keep Turnstile off for the API tests; the secret check below sets it again
wpeval("update_option('jmb_pay_since', time() - 60, false);")
wpeval("update_option('jmb_extra', " + "[" + ",".join(f"['date' => '{day(n)}', 'time' => '10:00']" for n in (5, 6, 7, 8, 9, 10, 11, 12)) + "], false);")


def signed_book(date, email, xff, **extra):
    code, prev, _ = http("POST", WP + "/contract", {"kind": "full", "date": date, "start": "10:00", "name": extra.get("name", "T " + email.split("@")[0]),
                                                    "email": email, "location": extra.get("location", "Mayfield Park")})
    return book(date, email, xff, agree_esign=1, agree_terms=1, signature=extra.pop("signature", "T Sig"), contract_hash=prev.get("hash", ""), **extra)


# ======================================================== 1. injection and escaping
code, j, bx = signed_book(day(5), "xss@example.com", "10.9.0.1", name="X " + XSS, notes=XSS, location="Park " + XSS, signature="S " + XSS)
check("A1 booking with script/HTML in every field is accepted but stored as plain text", code == 200 and "<script" not in json.dumps(db(f"SELECT name, notes, location FROM wp_jmb_bookings WHERE id={bx}")), (code, j))
sid = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={bx}")[0][0]
completed(sid, {"booking_id": str(bx)}, 15000, str(bx))
ctoken = db(f"SELECT token FROM wp_jmb_contracts WHERE booking_id={bx}")[0][0]
ptoken = db(f"SELECT token FROM wp_jmb_payments WHERE booking_id={bx}")[0][0]
admin = login("admin", "admin")
pages = {
    "bookings list": admin.open(BASE + "/wp-admin/admin.php?page=jmb&show=all").read().decode(),
    "admin contract": admin.open(BASE + "/wp-admin/admin.php?page=jmb-contract&id=" + db(f"SELECT id FROM wp_jmb_contracts WHERE booking_id={bx}")[0][0]).read().decode(),
    "client contract": http("GET", f"{BASE}/?jmb_contract={ctoken}", raw=True)[1].decode(),
    "pay page": http("GET", f"{BASE}/?jmb_pay={ptoken}", raw=True)[1].decode(),
}
leaks = [name for name, pg in pages.items() if re.search(r"<script>alert|<img src=x onerror", pg)]
check("A2 nothing a client types is ever output as HTML (admin list, contract pages, pay page)", not leaks, leaks)

weird = ["2026-13-45", "2026-12-05' OR 1=1 --", "../../etc/passwd", "2026-12-05\x00", "9999-99-99", ""]
codes = [book(w, f"inj{i}@example.com", f"10.9.1.{i}")[0] for i, w in enumerate(weird)]
check("A3 malformed and SQL-injection dates are refused, not errors", all(c == 400 for c in codes), codes)
codes = [http("GET", WP + "/availability&month=" + urllib.parse.quote(m))[0] for m in ["2026-12' OR '1'='1", "-1", "2026-12;DROP TABLE wp_jmb_bookings"]]
check("A3b odd month values fall back safely; the tables are still there", all(c == 200 for c in codes) and db("SHOW TABLES LIKE 'wp_jmb_bookings'") == [["wp_jmb_bookings"]], codes)
codes = [http("GET", WP + "/status&ref=" + urllib.parse.quote(r))[0] for r in ["' OR 1=1 --", "A" * 31, "A" * 33, ctoken + "x"]]
codes += [http("GET", f"{BASE}/?jmb_pay=" + urllib.parse.quote(r), raw=True)[0] for r in ["' OR 1=1 --", ptoken[:-1], ptoken + "A"]]
codes += [http("GET", f"{BASE}/?jmb_contract=" + urllib.parse.quote(r), raw=True)[0] for r in ["' OR '1'='1", ctoken[:-1]]]
check("A4 tokens must be exactly 32 letters/digits; anything else is 'not found'", all(c == 404 for c in codes), codes)

# ======================================================== 2. mass assignment / price tampering
code, j, bm = book(day(6), "tamper@example.com", "10.9.2.1", status="confirmed", amount_cents=1, id=1, source="manual", amount="0.01",
                   agree_esign=1, agree_terms=1, signature="T Signer", contract_hash=http("POST", WP + "/contract", {"kind": "full", "date": day(6), "start": "10:00",
                   "name": "T tamper", "email": "tamper@example.com", "location": "Mayfield Park"})[1]["hash"])
row = db(f"SELECT status, amount_cents, source FROM wp_jmb_bookings WHERE id={bm}")[0]
sm = mock_sessions()[db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={bm}")[0][0]]["params"]
check("A5 extra fields (status, amount, source, id) are ignored; Stripe is asked for the real retainer",
      code == 200 and row == ["pending", "15000", "web"] and sm["line_items[0][price_data][unit_amount]"] == "15000", (code, j, row))
code, _, _ = book(day(7), "redir@example.com", "10.9.2.2", return_url="javascript:alert(1)//127.0.0.1:18080/",
                  agree_esign=1, agree_terms=1, signature="T Signer", contract_hash=http("POST", WP + "/contract", {"kind": "full", "date": day(7), "start": "10:00",
                  "name": "T redir", "email": "redir@example.com", "location": "Mayfield Park"})[1]["hash"])
br = int(db("SELECT MAX(id) FROM wp_jmb_bookings")[0][0])
su = mock_sessions()[db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={br}")[0][0]]["params"]["success_url"]
check("A5b a javascript:/foreign return address cannot be smuggled into Stripe's return link", su.startswith(BASE + "/?jmb=paid") or su.startswith("http://127.0.0.1:18080/"), su)

# ======================================================== 3. forged webhooks
p_open = db(f"SELECT id, token FROM wp_jmb_payments WHERE booking_id={bx}")[0]
evt = {"type": "checkout.session.completed", "data": {"object": {"id": sid, "metadata": {"booking_id": str(bm)}, "payment_status": "paid", "amount_total": 15000}}}
codes = [http("POST", WP + "/stripe", json.dumps(evt).encode())[0],
         signed(evt, secret=b"whsec_guessed")[0],
         signed(evt, t=int(time.time()) - 3600)[0]]
check("A6 webhook without a signature, with a wrong secret, or replayed an hour later -> refused", codes == [400, 400, 400] and db(f"SELECT status FROM wp_jmb_bookings WHERE id={bm}") == [["pending"]], codes)

# ======================================================== 4. double payments on a balance
p_url = f"{BASE}/?jmb_pay={p_open[1]}"
form = {"Content-Type": "application/x-www-form-urlencoded"}
http("POST", p_url, b"", form, raw=True, follow=False)
s1 = db(f"SELECT stripe_session FROM wp_jmb_payments WHERE id={p_open[0]}")[0][0]
http("POST", p_url, b"", form, raw=True, follow=False)                          # second tab
s2 = db(f"SELECT stripe_session FROM wp_jmb_payments WHERE id={p_open[0]}")[0][0]
check("A7 opening the pay page twice expires the first Stripe checkout (so it cannot also be paid)", s1 != s2 and mock_sessions()[s1]["status"] == "expired", (s1, s2))
wpeval("delete_option('jmb_test_mail');")
completed(s2, {"jmb_payment": p_open[0]}, 20000, pi="pi_second")
completed(s1, {"jmb_payment": p_open[0]}, 20000, pi="pi_first")                 # the race: first tab paid anyway
alert = [m for m in mails() if "twice" in m["subject"].lower() or "double" in m["subject"].lower()]
check("A7b if both still get paid, she is told to refund one (the second payment is not silently lost)",
      db(f"SELECT status FROM wp_jmb_payments WHERE id={p_open[0]}") == [["paid"]] and len(alert) == 1 and "pi_first" in alert[0]["message"], [m["subject"] for m in mails()])

code, j, bc = signed_book(day(8), "change@example.com", "10.9.3.1")
completed(db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={bc}")[0][0], {"booking_id": str(bc)}, 15000, str(bc))
pc = db(f"SELECT id, token FROM wp_jmb_payments WHERE booking_id={bc}")[0]
http("POST", f"{BASE}/?jmb_pay={pc[1]}", b"", form, raw=True, follow=False)
old = db(f"SELECT stripe_session FROM wp_jmb_payments WHERE id={pc[0]}")[0][0]
wpeval(f"wp_set_current_user(1); $_GET = ['pid' => {pc[0]}, 'do' => 'amount', 'amount' => '275', '_wpnonce' => wp_create_nonce('jmb_pay_act_{pc[0]}')]; $_REQUEST = $_GET;"
       "add_filter('wp_redirect', function ($l) { exit; }); do_action('admin_post_jmb_pay_act');")
check("A8 changing the amount expires a checkout already open at the old amount", mock_sessions()[old]["status"] == "expired")
http("POST", f"{BASE}/?jmb_pay={pc[1]}", b"", form, raw=True, follow=False)
old2 = db(f"SELECT stripe_session FROM wp_jmb_payments WHERE id={pc[0]}")[0][0]
wpeval(f"wp_set_current_user(1); $_GET = ['pid' => {pc[0]}, 'do' => 'paid', '_wpnonce' => wp_create_nonce('jmb_pay_act_{pc[0]}')]; $_REQUEST = $_GET;"
       "add_filter('wp_redirect', function ($l) { exit; }); do_action('admin_post_jmb_pay_act');")
check("A8b marking it paid by hand (cash) expires the open card checkout", mock_sessions()[old2]["status"] == "expired")

hits = []
for i in range(14):
    hits.append(http("POST", f"{BASE}/?jmb_pay={p_open[1]}", b"", form, raw=True, follow=False)[0])
code, j, bz = signed_book(day(9), "spam@example.com", "10.9.4.1")
completed(db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={bz}")[0][0], {"booking_id": str(bz)}, 15000, str(bz))
pz = db(f"SELECT token FROM wp_jmb_payments WHERE booking_id={bz}")[0][0]
hits = [http("POST", f"{BASE}/?jmb_pay={pz}", b"", form, raw=True, follow=False)[0] for _ in range(14)]
check("A9 the pay button cannot be used to flood her Stripe account with checkouts", hits.count(303) <= 10 and 429 in hits, hits)

# ======================================================== 5. someone else's checkout
code, j, bv = signed_book(day(10), "victim@example.com", "10.9.5.1")
vs = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={bv}")[0][0]
code, j, ba = signed_book(day(11), "victim@example.com", "10.9.5.2", client_key="A" * 32)
check("A10 a stranger using the victim's email cannot cancel the victim's checkout in progress",
      db(f"SELECT status FROM wp_jmb_bookings WHERE id={bv}") == [["pending"]] and mock_sessions()[vs]["status"] == "open" and code == 409, (code, j))
code, j, bs = signed_book(day(11), "victim@example.com", "10.9.5.1")
check("A10b the victim themself (same browser) can start over: the old checkout is released", code == 200 and db(f"SELECT status FROM wp_jmb_bookings WHERE id={bv}") == [["cancelled"]], (code, j))

# ======================================================== 6. access control
wpeval("if (!get_user_by('login', 'sub')) wp_create_user('sub', 'subpass', 'sub@example.com');")
sub = login("sub", "subpass")
denied = []
for path in ["/wp-admin/admin.php?page=jmb", "/wp-admin/admin.php?page=jmb-settings", "/wp-admin/admin.php?page=jmb-contract&id=1"]:
    try: body = sub.open(BASE + path).read().decode()
    except urllib.error.HTTPError as e: body = e.read().decode()
    denied.append("Sorry, you are not allowed" in body or "not allowed" in body)
sub_nonce = wpeval("wp_set_current_user(get_user_by('login', 'sub')->ID); echo wp_create_nonce('jmb_pay_act_" + p_open[0] + "');")
try: r = sub.open(BASE + f"/wp-admin/admin-post.php?action=jmb_pay_act&do=void&pid={p_open[0]}&_wpnonce={sub_nonce}").read().decode()
except urllib.error.HTTPError as e: r = e.read().decode()
check("A11 a logged-in non-admin (subscriber) cannot open the booking pages or run admin actions", all(denied) and "No." in r, (denied, r[:80]))
anon = []
for act in ["jmb_pay_act&do=void&pid=1", "jmb_act&do=cancel&id=1", "jmb_save", "jmb_retry_emails", "jmb_contract_pdf&id=1"]:
    c, b2, _ = http("GET", f"{BASE}/wp-admin/admin-post.php?action={act}", raw=True)
    anon.append(c)
check("A11b logged-out visitors get nothing from admin actions", all(c in (400, 403, 500) or c == 200 for c in anon) and db(f"SELECT status FROM wp_jmb_payments WHERE id={p_open[0]}") == [["paid"]], anon)

# ======================================================== 7. data exposure
pub = json.dumps(http("GET", WP + f"/availability&month={day(5)[:7]}")[1]) + json.dumps(http("GET", WP + f"/availability&month={day(40)[:7]}")[1])
check("A12 the public calendar never shows client names, emails or typed addresses", not any(x in pub for x in ["victim@", "xss@", "T victim", "onerror", "Park <"]))
settings(turnstile_secret="0x4AAAAAAATurnstileSecretValue")
st = admin.open(BASE + "/wp-admin/admin.php?page=jmb-settings").read().decode()
settings(turnstile_secret="")
check("A13 the settings page never shows saved secrets (Stripe key, webhook secret, Google key, Turnstile secret)",
      not any(x in st for x in ["sk_test_mock", "whsec_testsecret123", "BEGIN PRIVATE KEY", "TurnstileSecretValue"]))
gj = base64.b64encode(json.dumps({"client_email": "c@x", "private_key": "k"}).encode()).decode()
const = wpeval("define('JMB_STRIPE_SECRET', 'sk_test_fromconfig'); define('JMB_GCAL_JSON', base64_decode('" + gj + "'));"
              "$s = jmb_settings(); echo $s['stripe_secret'], '|', json_decode($s['gcal_json'], true)['client_email'] ?? '';")
check("A14 secrets can live in wp-config.php (constants win over the database)", const == "sk_test_fromconfig|c@x", const)

# ======================================================== 8. amplification and framing
before = http("GET", MOCK + "/gcal.json")[1]["list_calls"]
for m in ["2031-01", "2032-02", "2033-03", "2099-12", "1999-01"]:
    http("GET", WP + "/availability&month=" + m)
after = http("GET", MOCK + "/gcal.json")[1]["list_calls"]
check("A15 asking for months far outside the booking window does not hit Google Calendar", after == before, (before, after))
_, _, h1 = http("GET", f"{BASE}/?jmb_pay={p_open[1]}", raw=True)
_, _, h2 = http("GET", f"{BASE}/?jmb_contract={ctoken}", raw=True)
check("A16 the pay page and contract page cannot be framed by another site (clickjacking) and are not MIME-sniffed",
      all(h.get("X-Frame-Options") == "DENY" and h.get("X-Content-Type-Options") == "nosniff" for h in (h1, h2)), (h1.get("X-Frame-Options"), h2.get("X-Frame-Options")))

print(f"\n{ok} passed, {bad} failed")
raise SystemExit(1 if bad else 0)
