"""Stand-ins for the outside services jm-booking talks to, for tests only.

Stripe:  POST /v1/checkout/sessions, POST /v1/checkout/sessions/<id>/expire, GET /v1/checkout/sessions,
         GET /pay/<id> (fake hosted checkout page with Pay / Back links)
Google:  POST /token (service-account JWT grant), /calendar/v3/calendars/<cal>/events (list, insert, update, delete)
Helpers: GET /sessions.json, GET /gcal.json, POST /gcal/fixture (her own events), POST /gcal/fail ({"on": bool})
"""
import base64, itertools, json, re, urllib.parse
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SESSIONS = {}
COUNTER = itertools.count(1)
PUBLIC = "http://127.0.0.1:18081"

FIXTURE = []        # events she put in her calendar herself
CREATED = {}        # events the plugin inserted: id -> event
GCAL_FAIL = {"on": False}
TOKENS = []         # decoded JWT claims of every token request
EVENTS_RE = re.compile(r"^/calendar/v3/calendars/([^/]+)/events(?:/([^/]+))?$")


def ts(v):
    return datetime.fromisoformat(v.replace("Z", "+00:00")).timestamp()


def event_range(e):
    s, en = e["start"], e["end"]
    if "date" in s:
        return ts(s["date"] + "T00:00:00-06:00"), ts(en["date"] + "T00:00:00-06:00")
    return ts(s["dateTime"]), ts(en["dateTime"])


class H(BaseHTTPRequestHandler):
    def _send(self, code, obj, ctype="application/json"):
        body = b"" if obj is None else (obj if isinstance(obj, (bytes, str)) else json.dumps(obj))
        body = body.encode() if isinstance(body, str) else body
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _body(self):
        n = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(n).decode() if n else ""

    def _stripe_auth(self):
        if not self.headers.get("Authorization", "").startswith("Bearer sk_test_"):
            self._send(401, {"error": {"message": "Invalid API Key provided"}})
            return False
        return True

    def _google_auth(self):
        if self.headers.get("Authorization") != "Bearer ya29.mock":
            self._send(401, {"error": {"code": 401, "message": "Request had invalid authentication credentials."}})
            return False
        if GCAL_FAIL["on"]:
            self._send(503, {"error": {"code": 503, "message": "Backend Error"}})
            return False
        return True

    # ------------------------------------------------------------------ GET
    def do_GET(self):
        u = urllib.parse.urlparse(self.path)
        q = dict(urllib.parse.parse_qsl(u.query))
        if u.path == "/sessions.json":
            return self._send(200, SESSIONS)
        if u.path == "/gcal.json":
            return self._send(200, {"created": CREATED, "fixture": FIXTURE, "tokens": TOKENS})
        if u.path.startswith("/pay/"):
            sid = u.path.split("/")[2]
            s = SESSIONS.get(sid)
            if not s:
                return self._send(404, "no such session", "text/plain")
            html = f"""<!doctype html><title>Mock Stripe Checkout</title><h1>Mock Stripe Checkout</h1>
<p id=amount>{s['params'].get('line_items[0][price_data][unit_amount]')} cents - {s['params'].get('line_items[0][price_data][product_data][name]')}</p>
<a id=pay href="{s['params']['success_url']}">Pay</a> <a id=back href="{s['params']['cancel_url']}">Back</a>"""
            return self._send(200, html, "text/html")
        if u.path == "/v1/checkout/sessions":
            if self._stripe_auth():
                self._send(200, {"object": "list", "data": []})
            return
        m = EVENTS_RE.match(u.path)
        if m and not m.group(2):
            if not self._google_auth():
                return
            lo, hi = ts(q["timeMin"]), ts(q["timeMax"])
            items = [e for e in FIXTURE + list(CREATED.values()) if event_range(e)[0] < hi and event_range(e)[1] > lo]
            return self._send(200, {"items": items})
        self._send(404, {"error": {"message": "not found"}})

    # ----------------------------------------------------------------- POST
    def do_POST(self):
        path = urllib.parse.urlparse(self.path).path
        raw = self._body()
        if path == "/token":
            form = dict(urllib.parse.parse_qsl(raw))
            parts = form.get("assertion", "").split(".")
            if form.get("grant_type") != "urn:ietf:params:oauth:grant-type:jwt-bearer" or len(parts) != 3:
                return self._send(400, {"error": "invalid_grant"})
            claims = json.loads(base64.urlsafe_b64decode(parts[1] + "=" * (-len(parts[1]) % 4)))
            TOKENS.append(claims)
            if "calendar" not in claims.get("scope", ""):
                return self._send(400, {"error": "invalid_scope"})
            return self._send(200, {"access_token": "ya29.mock", "expires_in": 3600, "token_type": "Bearer"})
        if path == "/gcal/fixture":
            FIXTURE[:] = json.loads(raw)
            return self._send(200, {"ok": True})
        if path == "/gcal/fail":
            GCAL_FAIL["on"] = bool(json.loads(raw).get("on"))
            return self._send(200, {"ok": True})
        m = EVENTS_RE.match(path)
        if m and not m.group(2):
            if not self._google_auth():
                return
            ev = json.loads(raw)
            ev["id"] = f"evt{next(COUNTER)}"
            CREATED[ev["id"]] = ev
            return self._send(200, ev)
        # Stripe
        if not self._stripe_auth():
            return
        params = dict(urllib.parse.parse_qsl(raw))
        if path == "/v1/checkout/sessions":
            sid = f"cs_test_mock{next(COUNTER)}"
            SESSIONS[sid] = {"params": params, "status": "open"}
            return self._send(200, {"id": sid, "object": "checkout.session", "url": f"{PUBLIC}/pay/{sid}", "status": "open"})
        if path.startswith("/v1/checkout/sessions/") and path.endswith("/expire"):
            sid = path.split("/")[4]
            if sid in SESSIONS:
                SESSIONS[sid]["status"] = "expired"
                return self._send(200, {"id": sid, "status": "expired"})
            return self._send(404, {"error": {"message": "No such checkout.session"}})
        self._send(404, {"error": {"message": "not found"}})

    # ------------------------------------------------------------ PUT / DELETE
    def do_PUT(self):
        m = EVENTS_RE.match(urllib.parse.urlparse(self.path).path)
        if not (m and m.group(2)) or not self._google_auth():
            return self._send(404, {"error": {"code": 404, "message": "Not Found"}}) if not (m and m.group(2)) else None
        if m.group(2) not in CREATED:
            return self._send(404, {"error": {"code": 404, "message": "Not Found"}})
        ev = json.loads(self._body())
        ev["id"] = m.group(2)
        CREATED[ev["id"]] = ev
        self._send(200, ev)

    def do_DELETE(self):
        m = EVENTS_RE.match(urllib.parse.urlparse(self.path).path)
        if not (m and m.group(2)):
            return self._send(404, {"error": {"code": 404, "message": "Not Found"}})
        if not self._google_auth():
            return
        if CREATED.pop(m.group(2), None) is None:
            return self._send(410, {"error": {"code": 410, "message": "Resource has been deleted"}})
        self._send(204, None)

    def log_message(self, *a):
        pass


ThreadingHTTPServer(("0.0.0.0", 8000), H).serve_forever()
