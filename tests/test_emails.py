"""Automatic email tests (jm-booking 2.4): timing, late bookings, no duplicates, privacy, retries, tests from the settings page.
Dates are relative to today, so these tests do not go stale."""
import base64, datetime, html as html_mod, json, pathlib, re, subprocess, urllib.parse, urllib.request, http.cookiejar

BASE = "http://127.0.0.1:18080"
WPCLI = str(pathlib.Path(__file__).with_name("wpcli.sh"))
ok = bad = 0


def check(name, cond, extra=""):
    global ok, bad
    ok, bad = (ok + 1, bad) if cond else (ok, bad + 1)
    print(("PASS " if cond else "FAIL ") + name + (f"   [{str(extra)[:300]}]" if not cond else ""))


def wpeval(code):
    return subprocess.run([WPCLI, "eval", code], capture_output=True, text=True).stdout


def db(sql):
    out = subprocess.run(["docker", "exec", "jmbtest-db", "mariadb", "-uwp", "-pwp", "wp", "-N", "-e", sql], capture_output=True, text=True).stdout
    return [l.split("\t") for l in out.rstrip("\n").split("\n") if l != ""]   # keep empty last columns


def settings(**kw):
    wpeval("$s = get_option('jmb_settings', []); $n = json_decode(base64_decode('%s'), true); update_option('jmb_settings', array_merge($s, $n));"
           % base64.b64encode(json.dumps(kw).encode()).decode())


def today(days=0):
    return (datetime.date.today() + datetime.timedelta(days=days)).isoformat()


def booking(name, email, days, confirmed_hours_ago, kind="full", status="confirmed", location="Mayfield Park", start="15:30"):
    end = "17:30" if kind == "full" else "16:30"
    db(f"""INSERT INTO wp_jmb_bookings (kind,date,start_time,end_time,status,name,email,location,source,created_at,confirmed_at)
           VALUES ('{kind}','{today(days)}','{start}','{end}','{status}','{name}','{email}','{location}','web',
                   UTC_TIMESTAMP() - INTERVAL {confirmed_hours_ago} HOUR, UTC_TIMESTAMP() - INTERVAL {confirmed_hours_ago} HOUR)""")
    return int(db("SELECT MAX(id) FROM wp_jmb_bookings")[0][0])


def run():
    return json.loads(wpeval("echo wp_json_encode(jmb_run_auto_emails());"))


def mails():
    return json.loads(wpeval("echo wp_json_encode(get_option('jmb_test_mail', []));") or "[]")


def sent_to(email):
    return [m for m in mails() if m["to"] == email]


def log(bid):
    return {k: (s, n) for k, s, n in db(f"SELECT email_key, status, note FROM wp_jmb_emails WHERE booking_id={bid}")}


on = {k: {"on": 1, "hour": 0} for k in ("prep", "reminder", "thanks", "review")}
on["prep"].update(days=-7); on["reminder"].update(days=-2, late=1); on["thanks"].update(days=1); on["review"].update(days=21)
settings(business_name="Test Studio", emails=on, emails_minis=1, locations="Mayfield Park\nRed Bud Isle")

# ---- emails off by default: nothing goes out
wpeval("$s = get_option('jmb_settings'); unset($s['emails']); update_option('jmb_settings', $s);")
b0 = booking("Off Test", "off@example.com", 1, 240)
check("E0 everything is off by default", run() == {"sent": 0, "skipped": 0, "failed": 0} and not sent_to("off@example.com"))
db(f"DELETE FROM wp_jmb_bookings WHERE id={b0}")
settings(emails=on)

b1 = booking("Ann Early", "ann@example.com", 5, 240)            # booked 10 days ago, session in 5 days
b2 = booking("Ben Soon", "ben@example.com", 1, 240)             # booked 10 days ago, session tomorrow
b3 = booking("Cat Late", "cat@example.com", 1, 1)               # booked an hour ago, session tomorrow
b4 = booking("Dan Yesterday", "dan@example.com", -1, 480)       # session yesterday
b5 = booking("Eve Old", "eve@example.com", -30, 900)            # session a month ago (feature turned on late)
b6 = booking("Fay Review", "fay@example.com", -25, 900)         # session 25 days ago
b7 = booking("Gus Cancelled", "gus@example.com", 5, 240, status="cancelled")
b8 = booking("No Email", "", 1, 240)
b9 = booking("Mia Mini", "mia@example.com", 1, 240, kind="mini", start="14:00", location="Same spot as booking #3 (share privately)")

first = run()
check("E1 session in 5 days: prep tips sent, reminder not yet", log(b1) == {"prep": ("sent", "")} and len(sent_to("ann@example.com")) == 1, log(b1))
check("E2 session tomorrow, booked long ago: prep + reminder sent", set(log(b2)) == {"prep", "reminder"} and all(v[0] == "sent" for v in log(b2).values()), log(b2))
check("E3 booked an hour ago for tomorrow: prep skipped, reminder still sent",
      log(b3).get("prep") == ("skipped", "booked after the send time") and log(b3).get("reminder", ("",))[0] == "sent", log(b3))
check("E4 session yesterday: thank-you sent, before-session emails skipped",
      log(b4).get("thanks", ("",))[0] == "sent" and log(b4).get("prep", ("",))[0] == "skipped" and log(b4).get("reminder", ("",))[0] == "skipped", log(b4))
check("E5 turning emails on does not mail old clients (more than 7 days overdue)",
      not sent_to("eve@example.com") and log(b5).get("thanks") == ("skipped", "more than 7 days overdue") and log(b5).get("review") == ("skipped", "more than 7 days overdue"), log(b5))
check("E6 review request 4 days overdue still goes out", log(b6).get("review", ("",))[0] == "sent" and log(b6).get("thanks", ("",))[0] == "skipped", log(b6))
check("E7 cancelled bookings and bookings without an email get nothing", not sent_to("gus@example.com") and log(b7) == {} and log(b8) == {})

rem = next(m for m in sent_to("ben@example.com") if "See you" in m["subject"])
check("E8 fields filled in: first name, date, time, location, business name",
      rem["subject"] == f"See you {datetime.date.fromisoformat(today(1)).strftime('%A, %B %-d, %Y')}!" and rem["message"].startswith("Hi Ben,")
      and "3:30 PM – 5:30 PM" in rem["message"] and "Where: Mayfield Park" in rem["message"] and "Test Studio" in rem["message"] and "{" not in rem["message"], rem)
mini = next((m for m in sent_to("mia@example.com") if "See you" in m["subject"]), {})
check("E9 mini reminder hides the other client's private spot", "exact spot separately" in mini.get("message", "") and "booking #" not in mini.get("message", ""), mini.get("message"))

second = run()
check("E10 running again sends nothing twice", second["sent"] == 0 and second["skipped"] == 0 and first["sent"] >= 8, (first, second))

# ---- minis off
settings(emails_minis=0)
b10 = booking("Max Mini", "max@example.com", 1, 240, kind="mini", start="14:00")
run()
check("E11 with mini emails off, mini clients get nothing", not sent_to("max@example.com"))
settings(emails_minis=1)

# ---- one email switched off
settings(emails={**on, "reminder": {**on["reminder"], "on": 0}})
b11 = booking("Ivy NoReminder", "ivy@example.com", 1, 240)
run()
check("E12 a switched-off email is not sent", set(log(b11)) == {"prep"}, log(b11))
settings(emails=on)

# ---- failures are retried 3 times, then she is told
wpeval("update_option('jmb_test_mail_fail', 1);")
b12 = booking("Jon Fail", "jon@example.com", 5, 240)
run(); run()
check("E13 failed send is logged and retried", db(f"SELECT status, attempts FROM wp_jmb_emails WHERE booking_id={b12}") == [["failed", "2"]],
      db(f"SELECT status, attempts, note FROM wp_jmb_emails WHERE booking_id={b12}"))
run()
wpeval("delete_option('jmb_test_mail_fail');")
run()
check("E13b gives up after 3 attempts (no 4th try once mail works again)",
      db(f"SELECT status, attempts FROM wp_jmb_emails WHERE booking_id={b12}") == [["failed", "3"]] and not sent_to("jon@example.com"))

# ---- settings page: "Save and send me a test", list summary, no PHP warnings
jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
op.open(BASE + "/wp-login.php")
op.open(BASE + "/wp-login.php", urllib.parse.urlencode({"log": "admin", "pwd": "admin", "wp-submit": "Log In", "testcookie": "1"}).encode())
page = op.open(BASE + "/wp-admin/admin.php?page=jmb-settings").read().decode()
form = page[page.index('value="jmb_save"'):]
fields = {"action": "jmb_save", "_wpnonce": re.search(r'name="_wpnonce" value="([^"]+)"', form).group(1)}
# send every field of the settings form back as it is (like pressing the button in a browser), but change the prep subject
for m in re.finditer(r'<input[^>]*name="(jmb\[[^"]+)"[^>]*>', form):
    tag = m.group(0); name = m.group(1)
    if 'type="checkbox"' in tag or 'type="radio"' in tag:
        if "checked" in tag: fields.setdefault(name, re.search(r'value="([^"]*)"', tag).group(1))
    elif 'type="password"' not in tag:
        v = re.search(r'value="([^"]*)"', tag); fields[name] = __import__("html").unescape(v.group(1)) if v else ""
for m in re.finditer(r'<textarea[^>]*name="(jmb\[[^"]+)"[^>]*>(.*?)</textarea>', form, re.S): fields[m.group(1)] = __import__("html").unescape(m.group(2))
for m in re.finditer(r'<select[^>]*name="(jmb\[[^"]+)"[^>]*>(.*?)</select>', form, re.S):
    sel = re.search(r'<option value="([^"]*)"\s+selected', m.group(2)); fields[m.group(1)] = sel.group(1) if sel else ""
fields["jmb[emails][prep][subject]"] = "Prep for {first_name} – edited"
fields["jmb_testauto"] = "prep"
wpeval("delete_option('jmb_test_mail');")
res = op.open(BASE + "/wp-admin/admin-post.php", urllib.parse.urlencode(fields).encode()).read().decode()
t = mails()[-1] if mails() else {}
check("E14 'Save and send me a test' saves the edit, then sends a [Test] with it",
      t.get("subject") == "[Test] Prep for Sample – edited" and t.get("to") == "admin@example.com"
      and json.loads(wpeval("echo wp_json_encode(jmb_email_conf('prep'));"))["subject"] == "Prep for {first_name} – edited", t.get("subject"))
check("E14b the rest of the settings survived the save", json.loads(wpeval("echo wp_json_encode(jmb_email_conf('reminder'));"))["on"] == 1
      and wpeval("echo jmb_settings()['business_name'];") == "Test Studio")
lst = op.open(BASE + "/wp-admin/admin.php?page=jmb&show=all").read().decode()
dash = op.open(BASE + "/wp-admin/").read().decode()
failed_n = int(db("SELECT COUNT(*) FROM wp_jmb_emails WHERE status='failed' AND attempts>=3")[0][0])
check("E13c a red banner in wp-admin says how many emails failed and why (an email alert could not arrive)",
      failed_n >= 1 and f"{failed_n} automatic email" in dash and "Could not authenticate" in dash and "Retry them now" in dash, re.findall(r"notice-error.{0,300}", dash)[:1])
retry = html_mod.unescape(re.search(r'href="([^"]*action=jmb_retry_emails[^"]*)"', dash).group(1))
res_retry = op.open(urllib.parse.urljoin(BASE, retry)).read().decode()
check("E13d 'Retry them now' (email working again) delivers the missed emails and clears the banner",
      len(sent_to("jon@example.com")) == 1 and db(f"SELECT status FROM wp_jmb_emails WHERE booking_id={b12}") == [["sent"]]
      and "could not be sent" not in op.open(BASE + "/wp-admin/").read().decode() and "emails retried:" in urllib.parse.unquote(res_retry), db(f"SELECT status, attempts FROM wp_jmb_emails WHERE booking_id={b12}"))
warn = [re.sub(r"<[^>]+>", "", w)[:200] for w in re.findall(r"(?:<b>)?(?:Warning|Notice|Deprecated|Fatal error)(?:</b>)?:\s.{0,200}", page + res + lst)]
check("E15 bookings list shows which emails went out; no PHP warnings", "emails: prep tips ✓, reminder ✓" in lst and not warn, warn)

# ---- clean-up removes the email log with the booking
db(f"UPDATE wp_jmb_bookings SET date = '2020-01-04' WHERE id = {b5}")
wpeval("do_action('jmb_purge_old');")
check("E16 deleting an old booking also deletes its email log", db(f"SELECT COUNT(*) FROM wp_jmb_emails WHERE booking_id={b5}") == [["0"]])

print(f"\n{ok} passed, {bad} failed")
raise SystemExit(1 if bad else 0)
