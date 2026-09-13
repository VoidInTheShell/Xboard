# Xboard

<div align="center">

[![Telegram](https://img.shields.io/badge/Telegram-Channel-blue)](https://t.me/XboardOfficial)
![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-blue.svg)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## 📖 Introduction

Xboard is a modern panel system built on Laravel 11, focusing on providing a clean and efficient user experience.

## ✨ Features

- 🚀 Built with Laravel 12 + Octane for significant performance gains
- 🎨 Redesigned admin interface (React + Shadcn UI)
- 📱 Modern user frontend (Vue3 + TypeScript)
- 🐳 Ready-to-use Docker deployment solution
- 🎯 Optimized system architecture for better maintainability

## 🚀 Quick Start

```bash
git clone -b compose --depth 1 https://github.com/cedar2025/Xboard && \
cd Xboard && \
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    xboard php artisan xboard:install && \
docker compose up -d
```

> After installation, visit: http://SERVER_IP:7001  
> ⚠️ Make sure to save the admin credentials shown during installation

## 📖 Documentation

### 🔄 Upgrade Notice
> 🚨 **Important:** This version involves significant changes. Please strictly follow the upgrade documentation and backup your database before upgrading. Note that upgrading and migration are different processes, do not confuse them.

### Development Guides
- [Plugin Development Guide](./docs/en/development/plugin-development-guide.md) - Complete guide for developing XBoard plugins

### Deployment Guides
- [Deploy with 1Panel](./docs/en/installation/1panel.md)
- [Deploy with Docker Compose](./docs/en/installation/docker-compose.md)
- [Deploy with aaPanel](./docs/en/installation/aapanel.md)
- [Deploy with aaPanel + Docker](./docs/en/installation/aapanel-docker.md) (Recommended)

### Migration Guides
- [Migrate from v2board dev](./docs/en/migration/v2board-dev.md)
- [Migrate from v2board 1.7.4](./docs/en/migration/v2board-1.7.4.md)
- [Migrate from v2board 1.7.3](./docs/en/migration/v2board-1.7.3.md)

## 🛠️ Tech Stack

- Backend: Laravel 11 + Octane
- Admin Panel: React + Shadcn UI + TailwindCSS
- User Frontend: Vue3 + TypeScript + NaiveUI
- Deployment: Docker + Docker Compose
- Caching: Redis + Octane Cache

## 📷 Preview
![Admin Preview](./docs/images/admin.png)

![User Preview](./docs/images/user.png)

## ⚠️ Disclaimer

This project is for learning and communication purposes only. Users are responsible for any consequences of using this project.

## ❤️ Support The Project

If this project has helped you, donations are appreciated. They help support ongoing maintenance and would make me very happy.

TRC20: `TLypStEWsVrj6Wz9mCxbXffqgt5yz3Y4XB`

## 🌟 Maintenance Notice

This project is currently under light maintenance. We will:
- Fix critical bugs and security issues
- Review and merge important pull requests
- Provide necessary updates for compatibility

However, new feature development may be limited.

## 🔔 Important Notes

1. Restart required after modifying admin path:
```bash
docker compose restart
```

2. For aaPanel installations, restart the Octane daemon process

## Usage observability

Apply database migrations before enabling `USAGE_ENABLED=true`, or use the
authenticated admin/MCP `usage/settings/save` operation. Its settings are
`enabled`, `history_days`, `access_days`, and `identity_days` (retention: 7–730
days). Saved settings override the environment defaults. Run the normal Laravel
scheduler: `usage:maintain` aggregates online peaks; its scheduled `--prune` run
removes expired observations in bounded batches. No billing counters are reset
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

## 📈 Star History

[![Stargazers over time](https://starchart.cc/cedar2025/Xboard.svg)](https://starchart.cc/cedar2025/Xboard)
