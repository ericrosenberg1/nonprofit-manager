#!/usr/bin/env python3
"""A local SMTP server that accepts any login and records every message.

Usage: python3 tests/rig/smtp-sink.py <port> <outfile> [--delay SECONDS]
       [--fail-first N] [--fail-rcpt ADDRESS ...] [--fail-while FILE]

Each accepted message is appended to <outfile> as one JSON line:
{"mail_from": ..., "rcpt_to": [...], "auth": true|false, "data": "<raw message>",
"at": <unix time>}.
Used by tests/rig/mail-from-scope.php to see what WordPress hands the SMTP
server: the envelope sender and the From header of each message.
No TLS, so point the plugin at it with encryption "none" and auto TLS off.

The options make a server in trouble, for tests/rig/newsletter-queue-cron.php:
--delay waits that long before it answers each message's data, like a slow
provider. --fail-first answers the first N messages with "451 try again
later" and then takes everything. --fail-rcpt refuses one address every time.
--fail-while refuses every message while FILE exists.
"""
import argparse
import json
import os
import socketserver
import threading
import time

ARGS = argparse.ArgumentParser()
ARGS.add_argument("port", type=int)
ARGS.add_argument("outfile")
ARGS.add_argument("--delay", type=float, default=0.0)
ARGS.add_argument("--fail-first", type=int, default=0)
ARGS.add_argument("--fail-rcpt", action="append", default=[])
ARGS.add_argument("--fail-while", default="")
OPTS = ARGS.parse_args()
PORT = OPTS.port
OUT = OPTS.outfile
SEEN = {"messages": 0}
SEEN_LOCK = threading.Lock()


def refuse_next():
    """Count one more message and say whether the server refuses it."""
    with SEEN_LOCK:
        SEEN["messages"] += 1
        count = SEEN["messages"]
    if OPTS.fail_while and os.path.exists(OPTS.fail_while):
        return True
    return count <= OPTS.fail_first


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
                if refuse_next():
                    self.send("451 4.3.0 Try again later")
                    continue
                mail_from = line.split(":", 1)[1].strip().split(" ")[0].strip("<>")
                rcpts = []
                self.send("250 OK")
            elif cmd == "RCPT":
                rcpt = line.split(":", 1)[1].strip().strip("<>")
                if rcpt.lower() in [r.lower() for r in OPTS.fail_rcpt]:
                    self.send("550 5.1.1 No such user")
                    continue
                rcpts.append(rcpt)
                self.send("250 OK")
            elif cmd == "DATA":
                self.send("354 End data with <CR><LF>.<CR><LF>")
                body = []
                while True:
                    d = self.rfile.readline().decode(errors="replace")
                    if d in (".\r\n", ".\n", ""):
                        break
                    body.append(d[1:] if d.startswith("..") else d)
                if OPTS.delay:
                    time.sleep(OPTS.delay)
                with open(OUT, "a") as fh:
                    fh.write(json.dumps({"mail_from": mail_from, "rcpt_to": rcpts, "auth": authed, "data": "".join(body), "at": time.time()}) + "\n")
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
