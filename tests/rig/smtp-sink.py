#!/usr/bin/env python3
"""A local SMTP server that accepts any login and records every message.

Usage: python3 tests/rig/smtp-sink.py <port> <outfile>

Each accepted message is appended to <outfile> as one JSON line:
{"mail_from": ..., "rcpt_to": [...], "auth": true|false, "data": "<raw message>"}.
Used by tests/rig/mail-from-scope.php to see what WordPress hands the SMTP
server: the envelope sender and the From header of each message.
No TLS, so point the plugin at it with encryption "none" and auto TLS off.
"""
import json
import socketserver
import sys

PORT = int(sys.argv[1])
OUT = sys.argv[2]


class Handler(socketserver.StreamRequestHandler):
    def send(self, line):
        self.wfile.write((line + "\r\n").encode())
        self.wfile.flush()

    def handle(self):
        self.send("220 npmp-rig ESMTP")
        mail_from, rcpts, authed = "", [], False
        while True:
            raw = self.rfile.readline()
            if not raw:
                return
            line = raw.decode(errors="replace").rstrip("\r\n")
            cmd = line.split(" ", 1)[0].upper()
            if cmd in ("EHLO", "HELO"):
                self.wfile.write(b"250-npmp-rig\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n")
                self.wfile.flush()
            elif cmd == "AUTH":
                parts = line.split()
                if len(parts) > 1 and parts[1].upper() == "LOGIN":
                    self.send("334 VXNlcm5hbWU6")
                    self.rfile.readline()
                    self.send("334 UGFzc3dvcmQ6")
                    self.rfile.readline()
                elif len(parts) == 2:
                    self.send("334 ")
                    self.rfile.readline()
                authed = True
                self.send("235 2.7.0 Authentication successful")
            elif cmd == "MAIL":
                mail_from = line.split(":", 1)[1].strip().split(" ")[0].strip("<>")
                rcpts = []
                self.send("250 OK")
            elif cmd == "RCPT":
                rcpts.append(line.split(":", 1)[1].strip().strip("<>"))
                self.send("250 OK")
            elif cmd == "DATA":
                self.send("354 End data with <CR><LF>.<CR><LF>")
                body = []
                while True:
                    d = self.rfile.readline().decode(errors="replace")
                    if d in (".\r\n", ".\n", ""):
                        break
                    body.append(d[1:] if d.startswith("..") else d)
                with open(OUT, "a") as fh:
                    fh.write(json.dumps({"mail_from": mail_from, "rcpt_to": rcpts, "auth": authed, "data": "".join(body)}) + "\n")
                self.send("250 OK queued")
            elif cmd == "RSET":
                mail_from, rcpts = "", []
                self.send("250 OK")
            elif cmd == "QUIT":
                self.send("221 Bye")
                return
            else:
                self.send("250 OK")


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    with Server(("127.0.0.1", PORT), Handler) as srv:
        srv.serve_forever()
