#!/usr/bin/env python3
"""Run one regional LOG-0 probe point."""

import argparse
from collections import Counter
from contextlib import contextmanager
from datetime import datetime, timezone
import errno
import fcntl
import ipaddress
import json
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import sys
import subprocess
import time
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


POINTS = {"cn-shanghai", "cn-alt", "overseas-hk", "overseas-jp"}
RESOLVERS = [
    {"id": "alidns", "address": "223.5.5.5", "mainland": True},
    {"id": "cloudflare", "address": "1.1.1.1"},
    {"id": "google", "address": "8.8.8.8"},
]
MAX_AGE = 86400


class ProbeUnavailable(Exception):
    """This point cannot obtain a trustworthy sample."""


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def resolve(hostname, resolvers):
    answers = Counter()
    observations = []
    statuses = []
    for resolver in resolvers:
        found = set()
        for record in ("A", "AAAA"):
            try:
                response = subprocess.run(
                    ["dig", "@" + resolver["address"], hostname, record,
                     "+time=1", "+tries=1", "+noall", "+comments", "+answer"],
                    capture_output=True, text=True, timeout=3, check=False,
                ).stdout
            except subprocess.TimeoutExpired:
                statuses.append("ERROR")
                continue
            status = re.search(r"status: ([A-Z]+)", response)
            statuses.append(status[1] if status else "ERROR")
            for line in response.splitlines():
                fields = line.split()
                if len(fields) == 5 and not line.startswith(";") and fields[3] == record:
                    try:
                        found.add(str(ipaddress.ip_address(fields[4])))
                    except ValueError:
                        continue
        for address in sorted(found):
            answers[address] += 1
            observations.append({"resolved_ip": address, "resolver": resolver["id"]})

    if len(observations) > 128:
        raise ProbeUnavailable()
    if answers:
        return answers, observations, "ok"
    return answers, observations, "nxdomain" if statuses and set(statuses) == {"NXDOMAIN"} else "error"


def select_ip(entry, answers, last_ip):
    if answers:
        # Keep the current IP on ties to avoid reporting resolver ordering as rotation.
        return max(answers, key=lambda ip: (answers[ip], ip == last_ip,
                                           ipaddress.ip_address(ip).version == 4, ip))
    return last_ip or entry.get("bootstrap_ip")


def tcp_check(address, port):
    start = time.monotonic()
    try:
        with socket.create_connection((address, port), timeout=3):
            return "ok", round((time.monotonic() - start) * 1000)
    except TimeoutError:
        return "timeout", None
    except ConnectionRefusedError:
        return "refused", None
    except OSError as error:
        if error.errno in {errno.EMFILE, errno.ENFILE, errno.ENOMEM, errno.ENOBUFS,
                           errno.ENETDOWN, errno.EAFNOSUPPORT, errno.EADDRNOTAVAIL}:
            raise ProbeUnavailable() from None
        if error.errno == errno.ETIMEDOUT:
            return "timeout", None
        return "error", None


def packet_loss(address, enabled):
    if not enabled:
        return None
    try:
        result = subprocess.run(
            ["ping", "-n", "-q", "-c", "5", "-i", "0.2", "-w", "3", address],
            capture_output=True, text=True, timeout=5, check=False,
            env={**os.environ, "LC_ALL": "C"},
        )
    except subprocess.TimeoutExpired:
        return None
    match = re.search(r"([0-9.]+)% packet loss", result.stdout)
    return float(match[1]) if match else None


def curl_canary(url, proxy=None):
    options = {"url": url, "output": "/dev/null", "write-out": "%{http_code}",
               "max-time": "7", "connect-timeout": "4", "proto": "=https"}
    if proxy:
        options.update({"proxy": proxy[0], "proxy-user": proxy[1], "noproxy": ""})
    else:
        options["noproxy"] = "*"
    config = "".join(f"{key} = {json.dumps(value)}\n" for key, value in options.items())
    result = subprocess.run(
        ["curl", "-q", "--silent", "--config", "-"], input=config, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=9, check=False,
    )
    return result.returncode == 0 and result.stdout == "204"


def protocol_check(entry, address, canary):
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        socks_port = listener.getsockname()[1]

    credentials = os.urandom(16).hex()
    outbound = {**entry["outbound"], "tag": "entry", "server": address,
                "server_port": entry["port"]}
    config = json.dumps({
        "log": {"disabled": True},
        "inbounds": [{"type": "socks", "listen": "127.0.0.1", "listen_port": socks_port,
                      "users": [{"username": "log0", "password": credentials}]}],
        "outbounds": [outbound],
        "route": {"final": "entry"},
    }).encode()
    command = [entry.get("sing_box", "sing-box")]
    validation = subprocess.run(
        [*command, "check", "-c", "/dev/stdin"], input=config,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=5, check=False,
    )
    if validation.returncode:
        raise ProbeUnavailable()

    with subprocess.Popen(
        [*command, "run", "-c", "/dev/stdin"], stdin=subprocess.PIPE,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    ) as process:
        try:
            process.stdin.write(config)
            process.stdin.close()
            deadline = time.monotonic() + 3
            while process.poll() is None and time.monotonic() < deadline:
                if tcp_check("127.0.0.1", socks_port)[0] == "ok":
                    if curl_canary(canary, (f"socks5h://127.0.0.1:{socks_port}", "log0:" + credentials)):
                        return "ok"
                    if process.poll() is None and curl_canary(canary):
                        return "fail"
                    break
                time.sleep(0.06)
            raise ProbeUnavailable()
        finally:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()


def probe(entry, resolvers, canary, last_ip, minute):
    observations = []
    dns_state = "ok"
    if entry.get("hostname"):
        answers, observations, dns_state = resolve(entry["hostname"], resolvers)
        address = select_ip(entry, answers, last_ip)
        if answers and last_ip and address != last_ip:
            dns_state = "changed"
    else:
        address = entry["bootstrap_ip"]

    if not address:
        return None

    tcp, rtt = tcp_check(address, entry["port"])
    handshake = protocol_check(entry, address, canary) if tcp == "ok" else "na"
    loss = packet_loss(address, entry.get("icmp_echo", True))
    error = {"nxdomain": "DNS_NXDOMAIN", "error": "DNS_ERROR"}.get(dns_state)
    if tcp != "ok":
        error = "TCP_" + tcp.upper()
    elif handshake != "ok":
        error = "HANDSHAKE_FAILED"
    elif loss is not None and loss >= 20:
        error = "PACKET_LOSS"

    stamp = datetime.fromtimestamp(minute * 60, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    return {"entry_id": entry["entry_id"], "checked_at": stamp, "target_ip": address,
            "dns_state": dns_state, "tcp_state": tcp, "handshake_state": handshake,
            "loss_pct": loss, "rtt_ms": rtt, "error_class": error, "resolutions": observations}


class State:
    def __init__(self, path, capacity):
        self.db = sqlite3.connect(path)
        self.db.execute("PRAGMA secure_delete = ON")
        self.capacity = capacity
        self.db.executescript("""
            CREATE TABLE IF NOT EXISTS pending (
                id INTEGER PRIMARY KEY, minute INTEGER NOT NULL, result TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS pending_age ON pending (minute);
            CREATE TABLE IF NOT EXISTS counters (
                id INTEGER PRIMARY KEY CHECK (id = 1), dropped INTEGER NOT NULL, acked INTEGER NOT NULL
            );
            INSERT OR IGNORE INTO counters VALUES (1, 0, -1);
            CREATE TABLE IF NOT EXISTS cadence (
                entry_id INTEGER PRIMARY KEY, last_ip TEXT, next_minute INTEGER NOT NULL,
                fast INTEGER NOT NULL, stable_since INTEGER
            );
            CREATE TABLE IF NOT EXISTS rtt_baseline (
                entry_id INTEGER NOT NULL, day INTEGER NOT NULL, slot INTEGER NOT NULL,
                total INTEGER NOT NULL, samples INTEGER NOT NULL, PRIMARY KEY (entry_id, day, slot)
            );
        """)

    def expired(self, minute):
        with self.db:
            count = self.db.execute("DELETE FROM pending WHERE minute <= ?",
                                    (minute - MAX_AGE // 60,)).rowcount
            self.db.execute("UPDATE counters SET dropped = dropped + ?", (count,))
            self.db.execute("DELETE FROM rtt_baseline WHERE day < ?", (minute // 1440 - 7,))

    def schedule(self, entry_id):
        return self.db.execute(
            "SELECT last_ip, next_minute, fast, stable_since FROM cadence WHERE entry_id = ?", (entry_id,)
        ).fetchone()

    def finish(self, entry_id, minute, state, result):
        last_ip, scheduled, fast, stable_since = state or (None, minute, False, None)
        if minute > scheduled:
            stable_since = None
        day, minute_of_day = divmod(minute, 1440)
        slot = minute_of_day // 5
        with self.db:
            if result:
                rtt = result["rtt_ms"]
                total, samples = self.db.execute(
                    "SELECT SUM(total), SUM(samples) FROM rtt_baseline WHERE entry_id = ? AND slot = ? AND day < ?",
                    (entry_id, slot, day),
                ).fetchone()
                if rtt is not None and samples and rtt > 3 * total / samples:
                    result["error_class"] = result["error_class"] or "RTT_DEGRADED"
                if result["error_class"]:
                    fast, stable_since = True, None
                else:
                    if rtt is not None:
                        self.db.execute("""
                            INSERT INTO rtt_baseline VALUES (?, ?, ?, ?, 1)
                            ON CONFLICT (entry_id, day, slot) DO UPDATE SET
                                total = total + excluded.total, samples = samples + 1
                        """, (entry_id, day, slot, rtt))
                    if fast:
                        stable_since = minute if stable_since is None else stable_since
                        if minute - stable_since >= 30:
                            fast, stable_since = False, None
                last_ip = result["target_ip"]
                self.db.execute("INSERT INTO pending (minute, result) VALUES (?, ?)",
                                (minute, json.dumps(result, separators=(",", ":"))))
                overflow = self.db.execute("SELECT COUNT(*) FROM pending").fetchone()[0] - self.capacity
                if overflow > 0:
                    self.db.execute("DELETE FROM pending WHERE id IN (SELECT id FROM pending ORDER BY id LIMIT ?)",
                                    (overflow,))
                    self.db.execute("UPDATE counters SET dropped = dropped + ?", (overflow,))
            else:
                stable_since = None

            next_minute = minute + 1 if fast else minute + ((2 - minute) % 5 or 5)
            self.db.execute("INSERT OR REPLACE INTO cadence VALUES (?, ?, ?, ?, ?)",
                            (entry_id, last_ip, next_minute, fast, stable_since))

    def flush(self, url, point, secret, deadline):
        opener = build_opener(NoRedirect())
        while time.monotonic() < deadline:
            self.expired(int(time.time() // 60))
            dropped, acked = self.db.execute("SELECT dropped, acked FROM counters").fetchone()
            row = self.db.execute("SELECT id, result FROM pending ORDER BY id LIMIT 1").fetchone()
            if not row and dropped == acked:
                return
            payload = {"dropped_total": dropped, "result": json.loads(row[1]) if row else None}
            request = Request(url, data=json.dumps(payload).encode(), headers={
                "Authorization": "Bearer " + secret, "X-Log0-Probe": point,
                "Accept": "application/json", "Content-Type": "application/json",
            })
            rejected = False
            try:
                with opener.open(request, timeout=4) as response:
                    if json.loads(response.read(1024)).get("ack") is not True:
                        return
            except HTTPError as error:
                with error:
                    if error.code == 409 and row:
                        try:
                            conflict = json.loads(error.read(1024)).get("code") == "INGEST_CONFLICT"
                        except (OSError, ValueError, AttributeError):
                            return
                        if conflict:
                            print(f"LOG-0 {point}: INGEST_CONFLICT for queued sample {row[0]}", file=sys.stderr)
                            with self.db:
                                self.db.execute("UPDATE counters SET dropped = dropped + 1")
                                self.db.execute("DELETE FROM pending WHERE id = ?", (row[0],))
                            continue
                if error.code != 422 or not row:
                    return
                rejected = True
            except (OSError, ValueError, AttributeError):
                return
            with self.db:
                self.db.execute("UPDATE counters SET dropped = dropped + ?, acked = ?", (int(rejected), dropped))
                if row:
                    self.db.execute("DELETE FROM pending WHERE id = ?", (row[0],))


def validate_config(config):
    if config["point"] not in POINTS or not config["key"]:
        raise ValueError()
    for url in (config["ingest_url"], config["canary_url"]):
        if urlsplit(url).scheme != "https" or not urlsplit(url).hostname:
            raise ValueError()
    resolvers = config.get("resolvers", RESOLVERS)
    resolver_ids = {resolver["id"] for resolver in resolvers}
    if len(resolver_ids) != len(resolvers) or len(resolvers) < 3 or not any(r.get("mainland") for r in resolvers):
        raise ValueError()
    if len({ipaddress.ip_address(r["address"]) for r in resolvers}) != len(resolvers):
        raise ValueError()
    if not all(re.fullmatch(r"[a-zA-Z0-9_-]{1,64}", name) for name in resolver_ids):
        raise ValueError()

    entry_ids = set()
    for entry in config["entries"]:
        entry_id = entry["entry_id"]
        if type(entry_id) is not int or entry_id < 1 or entry_id in entry_ids:
            raise ValueError()
        entry_ids.add(entry_id)
        if not 1 <= entry["port"] <= 65535 or entry["outbound"]["type"] not in {"anytls", "trojan", "vless"}:
            raise ValueError()
        if entry.get("hostname") and not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9.-]{0,254}", entry["hostname"]):
            raise ValueError()
        if entry.get("bootstrap_ip") or not entry.get("hostname"):
            entry["bootstrap_ip"] = str(ipaddress.ip_address(entry["bootstrap_ip"]))
    if config["point"] == "cn-shanghai" and not config.get("patrol_lock"):
        raise ValueError()
    if not 1 <= config.get("max_pending", 4096) <= 100000:
        raise ValueError()


@contextmanager
def patrol_slot(path):
    if not path:
        yield
        return
    with Path(path).open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield


def run(config, state_dir, once):
    os.umask(0o077)
    state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (state_dir / "log0.lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state = State(state_dir / "log0.sqlite", config.get("max_pending", 4096))
        resolvers = config.get("resolvers", RESOLVERS)
        while True:
            minute = int(time.time() // 60)
            state.expired(minute)
            entries = config["entries"]
            offset = (minute // 5 + minute % 5) % max(1, len(entries))
            for entry in entries[offset:] + entries[:offset]:
                entry_id = entry["entry_id"]
                cadence = state.schedule(entry_id)
                if cadence and cadence[1] > minute:
                    continue
                if (not cadence or not cadence[2]) and minute % 5 != 2:
                    continue
                try:
                    with patrol_slot(config.get("patrol_lock")):
                        if int(time.time() // 60) != minute:
                            break
                        result = probe(entry, resolvers, config["canary_url"], cadence[0] if cadence else None, minute)
                except (ProbeUnavailable, subprocess.SubprocessError, OSError):
                    result = None
                state.finish(entry_id, minute, cadence, result)
            state.flush(config["ingest_url"], config["point"], config["key"], time.monotonic() + 8)
            if once:
                return
            delay = (minute + 1) * 60 - time.time()
            if delay > 0:
                time.sleep(delay)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("config", type=Path)
    parser.add_argument("state_dir", type=Path)
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    try:
        config = json.loads(args.config.read_text())
        validate_config(config)
        if not all(shutil.which(binary) for binary in ("curl", "dig", "ping")):
            raise ValueError()
        run(config, args.state_dir, args.once)
    except (ValueError, TypeError, KeyError, OSError, sqlite3.Error):
        parser.exit(1, "LOG-0 probe configuration or local storage unavailable\n")


if __name__ == "__main__":
    main()
