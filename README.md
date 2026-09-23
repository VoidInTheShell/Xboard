# Xboard

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-blue.svg)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## 📖 Introduction

Xboard is a modern panel system built on Laravel 12, focusing on providing a clean and efficient user experience.

## ✨ Features

- 🚀 Built with Laravel 12 + Octane for significant performance gains
- 🎨 Redesigned admin interface (React + Shadcn UI)
- 📱 Modern user frontend (Vue3 + TypeScript)
- 🐳 Ready-to-use Docker deployment solution
- 🎯 Optimized system architecture for better maintainability

## 🚀 Quick Start

The fastest path on a fresh Linux host is the pinned release installer
(one command, full suite with the Caddy HTTPS entry):

~~~bash
curl -fsSL -o install.sh https://github.com/VoidInTheShell/Xboard/releases/latest/download/install.sh
sudo ./install.sh --domain panel.example.com --email you@example.com
~~~

It deploys the Xboard backend, the DK_Theme user panel, the standalone
Admin, the updater, and the Caddy entry as one suite, registers the panel
certificate for automatic ACME, and prints the admin URL, credentials, and an
MCP key. Upgrades and rollbacks are performed from the Admin panel
(版本更新) afterwards — never by rerunning the installer.

The manual equivalent:

~~~bash
git clone --depth 1 https://github.com/VoidInTheShell/Xboard
cd Xboard
cp compose.sample.yaml compose.yaml

# Minimal deployment .env — do NOT cp .env.example over it; the installer
# merges missing defaults itself.
cat > .env <<'EOF'
XBOARD_DEPLOY_DIR=/opt/xboard
XBOARD_VERSION=vX.Y.Z
XBOARD_THEME_VERSION=vA.B.C
XBOARD_ADMIN_VERSION=vA.B.C
XBOARD_UPDATER_VERSION=vA.B.C
XBOARD_PANEL_URL=https://panel.example.com
EOF

install -d -m 0755 secrets
umask 027 && head -c 32 /dev/urandom | base64 | tr -d '=+/' > secrets/admin_route_token
chown root:1000 secrets/admin_route_token

./deploy/compose/validate.sh compose.yaml
docker compose --env-file .env -f compose.yaml up -d --wait
docker compose --env-file .env -f compose.yaml exec -T \
    -e ENABLE_SQLITE=1 -e ENABLE_REDIS=1 \
    -e ADMIN_ACCOUNT=admin@example.com \
    -e ADMIN_PASSWORD=your-password \
    xboard php artisan xboard:install
~~~

See [Deploy with Docker Compose](./docs/en/installation/docker-compose.md)
for the complete four-component guide, the Caddy entry overlay, and the
existing-reverse-proxy scenario.

The default template uses four long-lived services: the Xboard backend, the
DK_Theme user panel, the standalone Admin, and a private updater, plus the
one-shot updater bootstrap. All images use exact published tags taken from
the same Xboard release manifest component suite; the Admin and Updater tags
must match. The Updater has no public port and uses the Docker socket for
controlled Compose replacement, entry reloads, and recovery. Treat that
socket as host-level authority.

All GitHub-published Xboard, Admin, Theme, and Updater images are built for
linux/amd64 and linux/arm64. Local acceptance on Windows is intentionally
limited to linux/amd64; do not locally build or run ARM64.

> After installation, the user panel is served through the theme container
> (published fallback port 7002) and the Admin at
> `https://PANEL_HOST/<admin-path>/` through the theme.
> ⚠️ Make sure to save the admin credentials shown during installation

## 📖 Documentation

### 🔄 Upgrade Notice
> 🚨 **Important:** This version involves significant changes. Please strictly follow the upgrade documentation and backup your database before upgrading. Note that upgrading and migration are different processes, do not confuse them.

### Development Guides
- [Plugin Development Guide](./docs/en/development/plugin-development-guide.md) - Complete guide for developing XBoard plugins

### Deployment Guides
- [Deploy with Docker Compose](./docs/en/installation/docker-compose.md)

### Migration Guides
- [Migrate from v2board dev](./docs/en/migration/v2board-dev.md)
- [Migrate from v2board 1.7.4](./docs/en/migration/v2board-1.7.4.md)
- [Migrate from v2board 1.7.3](./docs/en/migration/v2board-1.7.3.md)

## 🛠️ Tech Stack

- Backend: Laravel 12 + Octane
- Admin Panel: React + Shadcn UI + TailwindCSS
- User Frontend: Vue3 + TypeScript + NaiveUI
- Deployment: Docker + Docker Compose
- Caching: Redis + Octane Cache

## 📷 Preview
![Admin Preview](./docs/images/admin.png)

![User Preview](./docs/images/user.png)

## ⚠️ Disclaimer

This project is for learning and communication purposes only. Users are responsible for any consequences of using this project.

## 🔔 Important Notes

1. Restart required after modifying admin path:
```bash
docker compose restart
```

## Usage observability

Apply database migrations before enabling `USAGE_ENABLED=true`, or use the
authenticated admin/MCP `usage/settings/save` operation. Its settings are
`enabled`, `history_days`, `access_days`, and `identity_days` (retention: 7–730
days). Saved settings override the environment defaults. Run the normal Laravel
scheduler: `usage:maintain` aggregates online peaks; `logs:maintain` applies the
configured retention and storage budgets in bounded batches. No billing counters are reset
or incremented by the observation ledger.

The user API (`/api/v1/user/usage`) and admin API (`/api/v2/{admin-path}/usage`)
provide `snapshot`, `events`, `ip`, and `leaderboard`. User scope is enforced by
the authenticated account. Admin additionally has `online`, `infrastructure`,
`policy`, `policy/save`, `settings`, and `settings/save`; these are also exposed
through the existing scoped, audited MCP operation bridge.

- Source identity is **user + canonical IP**, not a physical device ID. Node
  platform is unknown. Web browser/client hints and subscription User-Agent are
  inference only and are not used as trusted identity or authorization.
- Web visits and subscription pulls are recorded individually. Proxy access
  history records first-seen sources; IP traffic history is separately grouped
  by user, source IP, node, and hour. Public leaderboard email labels are masked.
- NIC RX/TX, raw proxy traffic, and rate-adjusted billing observations are
  separate measurements. NIC counters and process-level counters establish a
  baseline on first observation. Per-source counters carry an independent
  generation to prevent duplicate attribution after reporter restarts.
- Historical buckets have hourly precision (daily display uses UTC+8). Bytes
  recovered after a reporting gap remain known cumulative bytes, but their
  precise time distribution is not inferred; gap/unknown/overflow coverage is
  disclosed. An unreported process crash can lose its last in-memory interval.
  These records are not a replacement for billing or a provider's quota meter.
- IP queries are SQL-aggregated and paginated (200 rows per page, 93-day maximum
  range); source details are limited to 2,000 nodes. Node reports are authenticated,
  limited to 1 MiB and 2,000 counters/sources, rate-limited, and replay-fenced.
  A fresh empty snapshot means zero; stale or incomplete collection is unknown.
- Server quota rules are monthly NIC observation budgets: reset day (clamped
  to month end), timezone, GiB/TiB limit, RX/TX/both and warning percentage. Saving
  a rule never deletes history or automatically disconnects users.

Restrict access to these sensitive IP/access records and choose retention
appropriate to your deployment. Risk observations are prompts for review, not
automatic proof of subscription/key leakage or grounds for an automatic ban.

## 🤝 Contributing

Issues and Pull Requests are welcome to help improve the project.
