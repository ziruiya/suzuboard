# LOG-0 probe

Run one process per point with Python 3.9+, Linux `dig` and `ping`, curl with SOCKS5 support, and a sing-box build that supports the configured outbound protocols.

```sh
python3 tools/log0-probe/probe.py /etc/log0/probe.json /var/lib/log0
```

Use `cn-shanghai`, `cn-alt`, `overseas-hk`, and `overseas-jp` for the Shanghai, alternate mainland, Hong Kong, and Japan points respectively. Provision a distinct bearer secret at each point and set its matching panel environment variable in `config/log0.php`. Keep the probe configuration readable only by the service account. Use a dedicated HTTPS canary that returns 204 and is reachable directly from each probe point and through each entry. Use the same registry entry IDs at all points.

Example configuration with placeholder values:

```json
{
  "point": "cn-shanghai",
  "key": "replace-with-the-point-secret",
  "ingest_url": "https://panel.example.test/api/v2/diagnostics/log0/ingest",
  "canary_url": "https://canary.example.test/health",
  "patrol_lock": "/var/lock/fleet-sweep-probe.lock",
  "max_pending": 4096,
  "entries": [
    {
      "entry_id": 1,
      "hostname": "entry.example.test",
      "bootstrap_ip": "192.0.2.1",
      "port": 443,
      "icmp_echo": true,
      "outbound": {
        "type": "trojan",
        "password": "replace-with-the-test-credential",
        "tls": { "enabled": true, "server_name": "entry.example.test" }
      }
    }
  ]
}
```

- Use the entry's actual sing-box outbound settings. Supported types are `anytls`, `trojan`, and `vless`. The probe sets the dial IP and port. For direct IP entries omit `hostname` and provide `bootstrap_ip`. For hostnames `bootstrap_ip` is an optional known fallback if no resolver supplies an IP. If a first lookup returns no addresses and there is no fallback, the round has no probe result. Resolver overrides use `resolvers`, an array of distinct `id` and `address` pairs with `"mainland": true` on at least one actual mainland resolver. The defaults are AliDNS, Cloudflare and Google.
- Match `patrol_lock` to the file held by the local fleet-sweep launcher while its sing-box client runs. Shanghai requires this setting. Each probe runs serially, starts normal rounds on UTC minute offsets 02, 07, 12 and so on, and skips a sample if the patrol holds the lock. Keep the alternate mainland point on a separate egress. `--once` processes the current eligible slot and attempts buffered uploads.
- Set `icmp_echo` to false for entries where ICMP echo is intentionally disabled. This leaves loss unmeasured. TCP connect time supplies RTT. A TCP connection plus a TLS-validated HTTPS 204 through the configured proxy is required for protocol success. An unusable local sing-box instance or unreachable canary yields no sample.
- The spool contains only allowlisted diagnostics and point-local cadence state. It expires reports after 24 hours, evicts the oldest reports at capacity, and reports the cumulative drop count, including rejected samples. The panel acknowledges reports after a diagnostic transaction and tracks the last drop total per point in `storage/framework/cache/log0`. Use the same state directory on restart to preserve the cumulative count and pending queue.

## Panel API

Send the point in `X-Log0-Probe` and its secret as `Authorization: Bearer …`. `POST /api/v2/diagnostics/log0/ingest` accepts `dropped_total` and an optional `result`. Attach DNS observations as `result.resolutions` with `resolved_ip` and `resolver` per observation. The server assigns `probe_point` and `received_at`. Successful submissions return `{"ack":true}`.

Use the existing Sanctum admin bearer token at `/api/v2/<secure_path>/log0`.

| Method and path | Purpose |
| --- | --- |
| `POST /entries` | Register an entry |
| `PATCH /entries/{entry}` | Update entry registration |
| `POST /entries/{entry}/mappings` | Append a complete mapping with a UTC `valid_from` |
| `GET /mappings/resolve?backend_node_id=…&at=…` | Check historical mapping confidence and candidates |
| `PATCH /events/{incident}` | Record the notice, attribution, hypothesis, confidence or status |

Use UTC timestamps of the form `2026-11-28T02:02:00Z` in requests. Keep manual notes and hypotheses to summaries. Omit destination identifiers, links, payloads, user associations and source IPs. Seed an initial mapping with a known backend before relying on DNS candidate generation. A DNS change records `error_class=DNS_CHANGED` and leaves attribution at `UNKNOWN` for an administrator to review.
