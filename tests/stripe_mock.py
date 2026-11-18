"""Tiny Stripe API stand-in for testing jm-booking: checkout session create/expire/list + a fake hosted payment page."""
import json, itertools, urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SESSIONS = {}
COUNTER = itertools.count(1)
PUBLIC = "http://127.0.0.1:18081"


class H(BaseHTTPRequestHandler):
    def _send(self, code, obj, ctype="application/json"):
        body = obj if isinstance(obj, (bytes, str)) else json.dumps(obj)
        body = body.encode() if isinstance(body, str) else body
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _auth(self):
        a = self.headers.get("Authorization", "")
        if not a.startswith("Bearer sk_test_"):
            self._send(401, {"error": {"message": "Invalid API Key provided"}})
            return False
        return True

    def do_GET(self):
        u = urllib.parse.urlparse(self.path)
        if u.path == "/sessions.json":  # test helper: everything the plugin sent
            return self._send(200, SESSIONS)
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
            if self._auth():
                self._send(200, {"object": "list", "data": []})
            return
        self._send(404, {"error": {"message": "not found"}})

    def do_POST(self):
        if not self._auth():
            return
        n = int(self.headers.get("Content-Length") or 0)
        params = dict(urllib.parse.parse_qsl(self.rfile.read(n).decode()))
        path = urllib.parse.urlparse(self.path).path
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

    def log_message(self, *a):
        pass


ThreadingHTTPServer(("0.0.0.0", 8000), H).serve_forever()
