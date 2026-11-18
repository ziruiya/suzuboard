# SuzuBoard P1-A / P2-P3 installation and staging notes

**Only for new working branches and disposable staging. Never run on the production checkout.**

## Inputs / provenance

- SuzuBoard initial import HEAD: `cd7172a1484bc8925d3b5333f0d9072cc754be99`.
- Production source revision: `d826a3b32f73bde24acce2a3ab033c6538ce121d` (not proven to equal initial-import source commit).
- Admin dist verified upstream commit: `ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7`.
- Live old patch repository requires separate human audit: `46d589a`; patch 02 retired, chat-widget.js differs from production.

## P1-A: ingest admin assets as ordinary files

Run on Muse's trusted workstation. The commands below do NOT modify the upstream repository.

```bash
set -euo pipefail
git clone https://github.com/ziruiya/suzuboard.git suzuboard
cd suzuboard
git checkout -b p1-a-local-source-and-admin
# Confirm you have the expected baseline before continuing.
test "$(git rev-parse HEAD)" = cd7172a1484bc8925d3b5333f0d9072cc754be99
# Clone into a separate directory; do not attach a submodule.
workdir="$(mktemp -d)"
git clone https://github.com/cedar2025/xboard-admin-dist.git "$workdir/admin-dist"
git -C "$workdir/admin-dist" checkout --detach ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7
test "$(git -C "$workdir/admin-dist" rev-parse HEAD)" = ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7
# The imported repository has no gitlink. Ensure this is a normal directory.
if git ls-files --stage public/assets/admin | grep -q '^160000 '; then
  echo 'Unexpected gitlink; STOP and investigate' >&2; exit 1
fi
mkdir -p public/assets/admin
rsync -a --delete --exclude .git/ "$workdir/admin-dist/" public/assets/admin/
test -s public/assets/admin/index.html
# Remove only the obsolete module pointer, not any third-party license information.
git rm .gitmodules
# Bring in the files from this delivery: Dockerfile, .dockerignore, workflow, baseline JSON.
cp /path/to/delivery/Dockerfile Dockerfile
cp /path/to/delivery/.dockerignore .dockerignore
cp /path/to/delivery/.github/workflows/docker-publish.yml .github/workflows/docker-publish.yml
cp /path/to/delivery/UPSTREAM_BASELINE.json UPSTREAM_BASELINE.json
# Change admin_dist_bundled to true ONLY after the above tests.
python3 - <<'PYTHON'
import json
p='UPSTREAM_BASELINE.json'
d=json.load(open(p));d['admin_dist_bundled']=True
open(p,'w').write(json.dumps(d,indent=2,ensure_ascii=False)+'\n')
PYTHON
# Verify tracked regular files, not a gitlink.
git add Dockerfile .dockerignore .github/workflows/docker-publish.yml UPSTREAM_BASELINE.json public/assets/admin
git ls-files --stage public/assets/admin/index.html | grep '^100644\|^100755'
! git ls-files --stage public/assets/admin | grep -q '^160000 '
git diff --cached --stat
git commit -m 'build: bundle admin dist and build exact SuzuBoard source'
```

Licensing: audit redistributability and preserve all license/attribution requirements for admin assets. Do not copy production chat-widget.js from the stale patch repo. The current live patch must be inventoried separately.

## P2/P3: apply backend change on new branch

```bash
git checkout -b p2-p3-suzu-profiles
python3 /path/to/delivery/apply_p2_p3.py "$PWD"
php -l app/Http/Requests/Admin/ServerSave.php
php -l app/Http/Controllers/V2/Admin/Server/ManageController.php
php -l app/Models/SuzuNodeProfile.php
php -l app/Services/ServerService.php
php -l app/Protocols/ClashMeta.php
php -l app/Protocols/Stash.php
php -l app/Protocols/SingBox.php
php -l database/migrations/2026_11_19_000001_create_suzu_node_profiles_table.php
git diff --check
git diff --stat
```

**Warning:** This patch implements backend API, server-side profile validation, and subscription UDP *advertisement*. It does not provide a new React-admin selector (the repo contains only compiled admin dist), and it does **not** enforce UDP prohibition in the agent. SN1 remains unimplemented. Do not claim full production-ready UDP blocking or UI completion.

## Staging checks / gating

1. Separate MySQL 5.7 staging DB and staging volume; run `php artisan migrate --force` only there. Verify `suzu_node_profiles` has no duplicate `server_id` and no modification of old `v2_server` schema.
2. Import a sanitized subset of old server records; create SW1 (`type=vless`, TLS=1, network=ws) with `suzu_profile.code=sw1`, `udp_enabled=false`, `enabled=false`, `show=0` and SS1 (`trojan`, TLS=1, `network=grpc`) analogously. Both should save with linked `suzu_node_profiles` row. Deliberately mismatched profile/type must return validation error.
3. Save and re-read `protocol_settings`: `network_settings.path`, `network_settings.headers.Host`, and `network_settings.serviceName` must survive. Unknown top-level `protocol_settings.suzu_profile` must **not** be used.
4. Before exposing users, generate each native subscription format and parse in exact client versions. For Suzu-profile VLESS/Trojan, ClashMeta/Stash `udp` must be false; legacy nodes remain true. Sing-box SW1 must omit `packet_encoding` when false. Shadowrocket has no standard equivalent setting in the present builder, so it requires live UDP capability checks.
5. Agent gRPC: on ONE disposable staging machine with precisely the same agent binary checksum and machine-mode config as production, deploy SS1 only. Obtain an already authorized staging account, confirm the panel emits `network=grpc` and `networkSettings.serviceName`, and check the agent starts the corresponding sing-box listener without errors. Connect from mihomo/sing-box; verify TLS certificate, service name, traffic counters, restart persistence, disconnect/reconnect, memory use, and rollback to the prior config. Do not update production agents.
6. Compare unchanged A/B/C/VIP subscriptions and node configs against saved baseline snapshots (normalize dynamic ordering/ports). Check admin login and page assets, payment flows, machine heartbeat, subscriptions, and audit logs.
7. If automatic entrypoint `xboard:update` performs migrations on boot, explicitly account for this in staging and production release approval. Snapshot-and-restore DB separately from image rollback.
8. 48-72h canary: <=5 authorized test users per candidate node, daily cumulative cap 5 GiB as a TEST BUDGET (not a non-blocking guarantee). Log success rates, CPU/RAM, disconnects, TLS/HTTP errors, and no regressions. Production remains blocked pending user approval.

## Release invariants

- Only publish reviewed changes to `main`; deploy `ghcr.io/ziruiya/suzuboard@sha256:...`, never a moving tag.
- CI labels `[self-hosted,linux,x64]` must match an isolated runner. The runner must have multiarch QEMU/Buildx support; otherwise set platforms to the actual staging architecture.
- `UPSTREAM_BASELINE.json` currently records production revision, **not** an independently verified upstream import source SHA. Fill `source_snapshot_commit` after matching snapshots.
- Do not touch production DB, 45 existing nodes or 11 active agents until staging pass, backup restore drill, diff review, and explicit user approval.
