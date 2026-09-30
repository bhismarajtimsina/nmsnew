#!/usr/bin/env python3
from http.server import BaseHTTPRequestHandler, HTTPServer


HTML = b"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Oxidized Waiting For Devices</title>
  <style>
    body { font-family: sans-serif; margin: 2rem; color: #222; }
    .card { max-width: 760px; padding: 1.5rem; border: 1px solid #ddd; border-radius: 12px; }
    code { background: #f4f4f4; padding: 0.1rem 0.3rem; border-radius: 4px; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Oxidized is waiting for devices</h1>
    <p>The Oxidized web UI is online, but there are currently no devices available from
    <code>/api/v1/component/oxidized/internal/devices-list</code>.</p>
    <p>When at least one eligible device appears, the container will automatically start the real Oxidized service.</p>
  </div>
</body>
</html>
"""


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        body = HTML
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format, *args):
        return


HTTPServer(("0.0.0.0", 8888), Handler).serve_forever()
