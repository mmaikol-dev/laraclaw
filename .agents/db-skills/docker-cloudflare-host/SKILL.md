---
name: docker-cloudflare-host
description: Operate the host's Docker stack and Cloudflare Tunnel: health checks, logs, restarts, backups, publishing sites, and tunnel routes. Load before any server-room work.
category: system
created_by: user
version: 1
is_active: true
source: database
dependencies: []
---

# Docker + Cloudflare Tunnel Host — Operations Runbook

You are the operator of this host. You use Docker Compose for services
and a Cloudflare Tunnel for public exposure. There is **no inbound
port-forwarding** — everything public flows through the tunnel.

## 1. Host identity

- Host: `atlas-ThinkPad-T490` (username `atlas`).
- OS: Ubuntu/Debian with Docker Engine + Compose plugin.
- `cloudflared` v2026.8.2, running as systemd service `cloudflared`.

## 2. Docker — the WhatsApp stack

Project directory:
```
~/docker/whatsappserver
```
Compose file: `docker-compose.dev.yml`.

Containers:

| Container          | Purpose                     | Host port (loopback) | Notes                                    |
|--------------------|-----------------------------|----------------------|------------------------------------------|
| `wazuri-api`       | Backend API (NestJS)        | `127.0.0.1:2785`     | Health: `GET /api/health`                |
| `wazuri-dashboard` | Frontend dashboard (nginx)  | `127.0.0.1:2886`     | Serves the built web UI                  |
| `wazuri-redis`     | Redis cache (internal only) | not published        | Reached by the API over the Docker net   |

Docker network: `openwa-network`.

Data (persistent):
```
~/docker/whatsappserver/data   ->   /app/data   (inside wazuri-api)
```
This holds the WhatsApp session + SQLite database. **Back it up; never
delete it casually.**

Source repos (for rebuilds):
- API: `~/docker/whatsappserver` (and its `api/` subdir)
- Dashboard: `~/docker/whatsappserver/dashboard`

Typical commands (run from `~/docker/whatsappserver`):
```bash
docker compose -f docker-compose.dev.yml ps
docker compose -f docker-compose.dev.yml logs -f wazuri-api
docker compose -f docker-compose.dev.yml restart wazuri-api
docker compose -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.dev.yml down
```

## 3. Cloudflare Tunnel — public access

- Domain/zone: `sitebase.co.ke` (managed in your Cloudflare account).
- Tunnel: `wazuri` — Tunnel ID `759073dd-6847-4d35-9325-08bf1addd5e3`.
- The tunnel is **remotely managed** (config lives in the Cloudflare
  dashboard, not a local `config.yml`). The local systemd service only
  needs the tunnel token/credentials and runs `cloudflared tunnel run`.

Published hostnames (route = `hostname -> local service`):

| Hostname                       | Local target        | Status      |
|--------------------------------|---------------------|-------------|
| `wazuri.sitebase.co.ke`        | `localhost:2886`    | configured  |
| `wazuri-api.sitebase.co.ke`    | `localhost:2785`    | planned     |
| `ssh.sitebase.co.ke`           | `localhost:22`      | planned     |

Commands:
```bash
sudo systemctl status cloudflared
sudo systemctl restart cloudflared
sudo journalctl -u cloudflared -n 100 --no-pager
```

To add/change a route: Cloudflare dashboard -> Zero Trust -> Networks ->
Tunnels -> `wazuri` -> Public Hostname -> add `Service = http://localhost:PORT`.

## 4. Auth & secrets (handling rules)

- The API expects an `X-API-Key` header.
- API keys live at `GET/POST/DELETE /api/auth/api-keys` (admin scope).
- The default dev key `dev-admin-key` **must be revoked** before the
  API hostname is published.
- **Never** print, log, commit, or paste secrets (tunnel token, API
  keys, Cloudflare API tokens). Warn the owner immediately if one
  leaks.

## 5. Health checks (safe, read-only)

```bash
curl -fsS http://localhost:2785/api/health
curl -fsS -o /dev/null -w '%{http_code}\n' http://localhost:2886
docker compose -f docker-compose.dev.yml ps
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
sudo systemctl status cloudflared --no-pager
```
Confirm the hostnames resolve publicly:
```bash
curl -fsS -o /dev/null -w '%{http_code}\n' https://wazuri.sitebase.co.ke
```

## 6. Backups

```bash
mkdir -p ~/docker/whatsappserver/backups
tar czf ~/docker/whatsappserver/backups/data-$(date +%F).tgz \
  -C ~/docker/whatsappserver data
```
Keep at least the last several daily archives. A backup is only good if
you have verified you can read the archive.

## 7. Public exposure rule (default posture)

- All services bind to **loopback** (`127.0.0.1`). Public access is
  **only** through the tunnel.
- To publish a new service: add a Cloudflare Tunnel public hostname ->
  `http://localhost:PORT`. **Do not** open firewall ports or bind to
  `0.0.0.0` unless the owner explicitly asks.
- Protect admin UIs with a Cloudflare Access policy before exposing them.

## 8. What YOU (the agent) are allowed to do without asking

- Read-only checks: `docker ps`, `docker compose ps`, container logs,
  `systemctl status`, health `curl`, `df -h`, `free -m`, API GETs.
- Restart a single container, or `cloudflared`, if it is unhealthy.
- Create a backup of `data/` before a risky change.
- Start a new, separate project on a free loopback port (test the port
  is free first).

## 9. What you MUST ask the owner before doing

- Changing Cloudflare routes, DNS records, or Access policies.
- Rebuilding or updating images (`--build`, `git pull` in the stack).
- Rotating or revoking API keys.
- Changing which ports are published, or binding to `0.0.0.0`.
- Stopping the whole stack, or rebooting the host.
- Installing system packages or changing the Docker daemon config.

**Never** do the following:
- `docker compose down -v`  (destroys volumes)
- `docker volume prune`
- `docker system prune -a`
- `rm -rf ~/docker/whatsappserver/data`
- Publish any service on a public interface or open a firewall port.
- Expose an admin UI publicly without a Cloudflare Access policy.
- Send messages from a WhatsApp session unless explicitly asked.
- Print, log, commit, or paste any secret.
- Run destructive commands you do not fully understand.

## 10. Open items (as of 2026-10-15)

- [ ] VERIFY: `wazuri.sitebase.co.ke` (route target `localhost:2886`) loads.
- [ ] VERIFY: `dev-admin-key` is revoked (a request with it should 401),
      including after an API restart.
- [ ] VERIFY: Cloudflare Access protects the dashboard.
- [ ] VERIFY: `wazuri-api` hostname is added only after the default key is gone.
- [ ] VERIFY: WhatsApp session started (scan QR) if not already.
- [ ] Next: scheduled backups of `~/docker/whatsappserver/data`.
