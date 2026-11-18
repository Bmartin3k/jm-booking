"""Contract e-signing tests (jm-booking 2.3): preview, signing rules, evidence stored, PDF + email copy, privacy, retention."""
import base64, hashlib, hmac, http.cookiejar, json, pathlib, re, subprocess, time, urllib.error, urllib.parse, urllib.request

BASE = "http://127.0.0.1:18080"
WP = BASE + "/?rest_route=/jmb/v1"
HERE = pathlib.Path(__file__).parent
WPCLI = str(HERE / "wpcli.sh")
UA = "jmb-test-agent/1.0"
ok = bad = 0

TEMPLATE = """# Photography Agreement

This agreement is between {business_name} and {client_name} ({client_email}, {client_phone}) for a {session_type} on {session_date}, {session_time} ({session_length}), at {location}.

# Fees

The session fee is {price}. A non-refundable retainer of {retainer} is paid at booking — “no refunds”, café rules apply.

# Signed

Dated {today}."""


def check(name, cond, extra=""):
    global ok, bad
    ok, bad = (ok + 1, bad) if cond else (ok, bad + 1)
    print(("PASS " if cond else "FAIL ") + name + (f"   [{str(extra)[:300]}]" if not cond else ""))


def wpeval(code):
    return subprocess.run([WPCLI, "eval", code], capture_output=True, text=True).stdout


def db(sql):
    out = subprocess.run(["docker", "exec", "jmbtest-db", "mariadb", "-uwp", "-pwp", "wp", "-N", "-e", sql], capture_output=True, text=True).stdout
    return [l.split("\t") for l in out.strip().splitlines()]


def http(method, url, body=None, headers=None, raw=False):
    data = body if isinstance(body, bytes) else (json.dumps(body).encode() if body is not None else None)
    r = urllib.request.Request(url, data=data, method=method, headers={"Content-Type": "application/json", "User-Agent": UA, **(headers or {})})
    try:
        with urllib.request.urlopen(r) as resp:
            content = resp.read()
            return resp.status, (content if raw else json.loads(content or b"null")), dict(resp.headers)
    except urllib.error.HTTPError as e:
        content = e.read()
        try: return e.code, (content if raw else json.loads(content or b"null")), dict(e.headers)
        except ValueError: return e.code, content, dict(e.headers)


def settings(**kw):
    wpeval("$s = get_option('jmb_settings', []); $n = json_decode(base64_decode('%s'), true); update_option('jmb_settings', array_merge($s, $n));"
           % base64.b64encode(json.dumps(kw).encode()).decode())


def details(**kw):
    d = {"kind": "full", "date": "2026-12-05", "start": "15:30", "name": "Ann Client", "email": "ann@example.com", "phone": "512-555-0100",
         "location": "Our backyard 12 Elm St", "return_url": BASE + "/book/"}
    d.update(kw)
    return d


def book(body, xff):
    code, j, _ = http("POST", WP + "/book", body, {"X-Forwarded-For": xff})
    return code, j


def pay(bid):
    sid = db(f"SELECT stripe_session FROM wp_jmb_bookings WHERE id={bid}")[0][0]
    payload = json.dumps({"type": "checkout.session.completed", "data": {"object": {"id": sid, "client_reference_id": str(bid), "metadata": {"booking_id": str(bid)},
                          "payment_status": "paid", "payment_intent": "pi_c", "amount_total": 15000}}}).encode()
    t = str(int(time.time()))
    sig = hmac.new(b"whsec_testsecret123", t.encode() + b"." + payload, hashlib.sha256).hexdigest()
    http("POST", WP + "/stripe", payload, {"Stripe-Signature": f"t={t},v1={sig}"})


def mails():
    return json.loads(wpeval("echo wp_json_encode(get_option('jmb_test_mail', []));") or "[]")


def pdftotext(pdf_bytes):
    p = HERE / "_tmp_contract.pdf"
    p.write_bytes(pdf_bytes)
    try:
        r = subprocess.run(["docker", "run", "--rm", "-v", f"{HERE}:/d:ro", "alpine:3.20", "sh", "-c",
                            "apk add -q --no-cache poppler-utils >/dev/null 2>&1 && pdfinfo /d/_tmp_contract.pdf | grep -E '^(Pages|Title)' && pdftotext -layout /d/_tmp_contract.pdf -"],
                           capture_output=True, text=True)
        return r.stdout + r.stderr
    finally:
        p.unlink()


# ---------------------------------------------------------------- setup
settings(stripe_secret="sk_test_mock", stripe_webhook="whsec_testsecret123", full_amount="150.00", mini_amount="50.00",
         full_price="$350", mini_price="$150", business_name="Test Studio", locations="Mayfield Park\nRed Bud Isle")

# C0: contracts off -> booking works without signing (the earlier behaviour)
code, j = book(details(date="2026-12-02", start="08:30", email="nocontract@example.com"), "10.6.0.1")
check("C0 contracts off: books without a signature", code == 200 and "checkout_url" in j, (code, j))
wpeval("$s = get_option('jmb_settings'); $s['contract_on'] = 1; update_option('jmb_settings', $s);")
code, j = book(details(date="2026-12-09", start="08:30", email="notext@example.com"), "10.6.0.2")
check("C0b switched on but no text yet: still books without one", code == 200, (code, j))

settings(contract_on=1, contract_full=TEMPLATE)
check("C1 calendar tells the page a contract is needed", http("GET", WP + "/availability&month=2026-12")[1]["contract"] is True)

code, j, _ = http("POST", WP + "/contract", {"kind": "full", "date": "2026-12-05", "start": "15:30"})
check("C2 preview needs name and email", code == 400, (code, j))

code, prev, _ = http("POST", WP + "/contract", details())
text = "\n".join(b[1] for b in prev.get("blocks", []))
check("C3 preview fills in every field", code == 200 and prev["blocks"][0] == ["h", "Photography Agreement"]
      and all(x in text for x in ["Test Studio", "Ann Client", "ann@example.com", "512-555-0100", "Full session", "Saturday, December 5, 2026",
                                  "3:30 PM – 5:30 PM", "120 minutes", "Our backyard 12 Elm St", "$350", "$150.00"]) and "{" not in text, text)
check("C3b preview returns a 64-character fingerprint and both consent statements", len(prev.get("hash", "")) == 64 and set(prev.get("consents", {})) == {"esign", "agree"})

# ------------------------------------------------------------- signing rules
code, j = book(details(), "10.6.0.3")
check("C4 booking without signing is refused", code == 400 and "tick both boxes" in j.get("message", ""), (code, j))
code, j = book(details(agree_esign=1, agree_terms=1, signature="A", contract_hash=prev["hash"]), "10.6.0.3")
check("C4b a one-letter signature is refused", code == 400 and "type your full name" in j.get("message", ""), (code, j))
code, j = book(details(agree_esign=1, agree_terms=1, signature="Ann Client", contract_hash="0" * 64), "10.6.0.3")
check("C5 signing a different text than the current one -> 409 'review again'", code == 409 and j.get("code") == "jmb_contract_changed", (code, j))
settings(contract_full=TEMPLATE + "\n\nNew clause added.")
code, j = book(details(agree_esign=1, agree_terms=1, signature="Ann Client", contract_hash=prev["hash"]), "10.6.0.3")
check("C6 template changed after the client read it -> 409", code == 409 and j.get("code") == "jmb_contract_changed", (code, j))
settings(contract_full=TEMPLATE)
check("C6b nothing was booked by the refused attempts", db("SELECT COUNT(*) FROM wp_jmb_bookings WHERE email='ann@example.com'") == [["0"]])

# ---------------------------------------------------------------- signed
code, j = book(details(agree_esign=1, agree_terms=1, signature="Ann M. Client", contract_hash=prev["hash"]), "10.6.0.4")
check("C7 signed booking goes to payment", code == 200 and "checkout_url" in j, (code, j))
bid = int(db("SELECT MAX(id) FROM wp_jmb_bookings")[0][0])
row = json.loads(wpeval(f"echo wp_json_encode(jmb_contract_for_booking({bid}));"))
check("C7b evidence stored: signature, name, email, IP, browser, both consents",
      row["signature"] == "Ann M. Client" and row["signer_name"] == "Ann Client" and row["signer_email"] == "ann@example.com"
      and row["ip"] == "10.6.0.4" and row["user_agent"] == UA and len(json.loads(row["consent"])) == 2, row)
check("C7c stored text matches its fingerprint and what the client was shown",
      hashlib.sha256(row["body"].encode()).hexdigest() == row["body_hash"] == prev["hash"] and "Our backyard 12 Elm St" in row["body"])

wpeval("delete_option('jmb_test_mail');")
pay(bid)
m = mails()
client = next((x for x in m if x["to"] == "ann@example.com"), None)
admin = next((x for x in m if "New booking" in x["subject"]), None)
check("C8 client confirmation email has the PDF attached and the private link",
      client and len(client["attachments"]) == 1 and client["attachments"][0]["name"].endswith(".pdf") and f"jmb_contract={row['token']}" in client["message"], client and client["message"])
check("C8b admin email names the signature and links the contract", admin and 'Contract signed by "Ann M. Client"' in admin["message"], admin and admin["message"])
check("C8c the temporary PDF file was deleted after sending", wpeval("echo count(glob(trailingslashit(get_temp_dir()) . 'jmb-*'));").strip() == "0")

pdf = base64.b64decode(client["attachments"][0]["data"])
txt = pdftotext(pdf)
flat = " ".join(txt.split())
check("C9 PDF is valid and readable", pdf.startswith(b"%PDF-1.4") and pdf.rstrip().endswith(b"%%EOF") and "Pages:" in txt and re.search(r"Title:\s+Photography Agreement", txt), txt[:300])
check("C9b PDF contains the contract, accents/quotes and the signature block",
      all(x in flat for x in ["PHOTOGRAPHY AGREEMENT", "Ann Client", "café rules apply", "ELECTRONIC SIGNATURE", "Ann M. Client", row["body_hash"], "IP address: 10.6.0.4"]), flat[:600])

# ------------------------------------------------------------ client page
code, page, hdr = http("GET", f"{BASE}/?jmb_contract={row['token']}", raw=True)
page = page.decode()
check("C10 private link shows the signed copy, not indexed, fingerprint OK", code == 200 and "Ann M. Client" in page and "unchanged since it was signed" in page
      and "noindex" in hdr.get("X-Robots-Tag", ""), (code, hdr.get("X-Robots-Tag")))
code, pdf2, hdr = http("GET", f"{BASE}/?jmb_contract={row['token']}&pdf=1", raw=True)
check("C10b PDF download from the private link", code == 200 and hdr.get("Content-Type", "").startswith("application/pdf") and pdf2.startswith(b"%PDF"))
code, _, _ = http("GET", f"{BASE}/?jmb_contract={'X' * 32}", raw=True)
check("C10c unknown link -> 404", code == 404)
db(f"UPDATE wp_jmb_contracts SET body = REPLACE(body, '$350', '$35') WHERE id = {row['id']}")
check("C11 tampered text is flagged on the page", "WARNING: this text no longer matches" in http("GET", f"{BASE}/?jmb_contract={row['token']}", raw=True)[1].decode())
db(f"UPDATE wp_jmb_contracts SET body = REPLACE(body, '$35', '$350') WHERE id = {row['id']}")

# --------------------------------------------- mini: the private spot stays private
code, mp, _ = http("POST", WP + "/contract", details(kind="mini", start="14:00", name="Mia Mini", email="mia@example.com", location=""))
mtext = "\n".join(b[1] for b in mp.get("blocks", []))
check("C12 mini contract does not reveal the other client's address", code == 200 and "Elm St" not in mtext and "to be shared by the photographer" in mtext and "Mini session" in mtext, mtext)
code, j = book(details(kind="mini", start="14:00", name="Mia Mini", email="mia@example.com", location="", agree_esign=1, agree_terms=1, signature="Mia Mini", contract_hash=mp["hash"]), "10.6.0.5")
check("C12b mini signs and books", code == 200, (code, j))

# ------------------------------------------------------------ admin pages
jar = __import__("http.cookiejar").cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
op.open(BASE + "/wp-login.php")
op.open(BASE + "/wp-login.php", urllib.parse.urlencode({"log": "admin", "pwd": "admin", "wp-submit": "Log In", "testcookie": "1"}).encode())
lst = op.open(BASE + "/wp-admin/admin.php?page=jmb&show=all").read().decode()
cp = op.open(BASE + f"/wp-admin/admin.php?page=jmb-contract&id={row['id']}").read().decode()
cp += op.open(BASE + "/wp-admin/admin.php?page=jmb-contract&id=999999").read().decode()   # not-found page too
st = op.open(BASE + "/wp-admin/admin.php?page=jmb-settings").read().decode()
warn = [f"{name}: " + re.sub(r"<[^>]+>", "", m)[:220] for name, pg in (("list", lst), ("contract", cp), ("settings", st))
        for m in re.findall(r"(?:<b>)?(?:Warning|Notice|Deprecated|Fatal error)(?:</b>)?:\s.{0,200}", pg)]
check("C13 admin list links the contract; contract + settings pages render with no PHP warnings",
      "contract signed" in lst and "Ann M. Client" in cp and "Download PDF" in cp and "Mini session contract" in st and not warn, warn)
link = re.search(r'href="([^"]*action=jmb_contract_pdf[^"]*)"', cp)
apdf = op.open(urllib.parse.urljoin(BASE, link.group(1).replace("&#038;", "&").replace("&amp;", "&"))).read() if link else b""
check("C13b admin PDF download works", apdf.startswith(b"%PDF"))

# ------------------------------------------------------------ retention
db(f"UPDATE wp_jmb_bookings SET date = '2020-01-04' WHERE id = {bid}")
wpeval("do_action('jmb_purge_old');")
check("C14 the booking clean-up deletes the old booking but keeps its signed contract",
      db(f"SELECT COUNT(*) FROM wp_jmb_bookings WHERE id={bid}") == [["0"]] and db(f"SELECT COUNT(*) FROM wp_jmb_contracts WHERE id={row['id']}") == [["1"]])
db(f"UPDATE wp_jmb_contracts SET signed_at = '2019-01-01 00:00:00' WHERE id = {row['id']}")
wpeval("do_action('jmb_purge_old');")
check("C14b contracts older than 6 years are deleted", db(f"SELECT COUNT(*) FROM wp_jmb_contracts WHERE id={row['id']}") == [["0"]])

# ------------------------------------------------- "Send me a test confirmation" button
def testmail(to):
    php = ("wp_set_current_user(1); $_POST = wp_slash(['to' => '%s', '_wpnonce' => wp_create_nonce('jmb_testmail')]); $_REQUEST = $_POST;"
           "add_filter('wp_redirect', function ($l) { echo urldecode($l); exit; }); do_action('admin_post_jmb_testmail');") % to
    return wpeval(php)

before = (db("SELECT COUNT(*) FROM wp_jmb_bookings"), db("SELECT COUNT(*) FROM wp_jmb_contracts"))
wpeval("delete_option('jmb_test_mail');")
out = testmail("owner@example.com")
m = mails()
tm = m[-1] if m else {}
tpdf = pdftotext(base64.b64decode(tm["attachments"][0]["data"])) if tm.get("attachments") else ""
check("T1 test confirmation sent to the given address, marked [Test], with a sample contract PDF",
      "sent to owner@example.com with a sample signed contract" in out and tm.get("to") == "owner@example.com" and tm.get("subject", "").startswith("[Test] You're booked!")
      and "Sample Client" in " ".join(tpdf.split()) and "In a real booking" in tm.get("message", "") and "jmb_contract=" not in tm.get("message", ""), (out, tm.get("subject")))
check("T1b nothing was booked or saved", (db("SELECT COUNT(*) FROM wp_jmb_bookings"), db("SELECT COUNT(*) FROM wp_jmb_contracts")) == before)
check("T1c the sample uses her real settings (price, retainer, first listed location)", "$150.00" in tm.get("message", "") and "Mayfield Park" in tm.get("message", ""), tm.get("message"))
settings(contract_on=0)
out = testmail("owner@example.com")
check("T2 with contracts off the test has no attachment and says so", "contracts are off" in out and not mails()[-1]["attachments"], out)
wpeval("update_option('jmb_test_mail_fail', 1);")
out = testmail("owner@example.com")
wpeval("delete_option('jmb_test_mail_fail');")
check("T3 a broken mail setup shows WordPress's actual error", "could not send it: SMTP Error: Could not authenticate." in out, out)
check("T4 a bad address is refused", "please enter an email address" in testmail("not-an-email"))

print(f"\n{ok} passed, {bad} failed")
raise SystemExit(1 if bad else 0)
