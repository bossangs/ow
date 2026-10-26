#!/usr/bin/env python3
"""极简本地 SMTP 收信桩：把收到的每封邮件原文写入目录，供测试断言。只监听 127.0.0.1。"""
import os
import socketserver
import sys
import time

OUT = os.environ.get("SMTP_SINK_DIR", "/tmp/smtp_sink")


class Handler(socketserver.StreamRequestHandler):
    def send(self, line):
        self.wfile.write((line + "\r\n").encode())

    def handle(self):
        self.send("220 sink ESMTP")
        data_mode = False
        buf = []
        while True:
            raw = self.rfile.readline()
            if not raw:
                return
            line = raw.decode("utf-8", "replace").rstrip("\r\n")
            if data_mode:
                if line == ".":
                    data_mode = False
                    os.makedirs(OUT, exist_ok=True)
                    name = os.path.join(OUT, "%d-%d.eml" % (int(time.time() * 1000), os.getpid()))
                    with open(name, "w", encoding="utf-8") as f:
                        f.write("\n".join(buf))
                    buf = []
                    self.send("250 queued")
                else:
                    buf.append(line[1:] if line.startswith("..") else line)
                continue
            cmd = line[:4].upper()
            if cmd in ("EHLO", "HELO"):
                self.wfile.write(b"250-sink\r\n250 8BITMIME\r\n")
            elif cmd == "DATA":
                data_mode = True
                self.send("354 end with .")
            elif cmd == "QUIT":
                self.send("221 bye")
                return
            else:
                self.send("250 ok")


class Server(socketserver.ThreadingMixIn, socketserver.TCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
    Server(("127.0.0.1", port), Handler).serve_forever()
