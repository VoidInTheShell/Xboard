# Xboard production deployment

This directory defines the JPGREEN production panel. The persistent runtime is exactly two containers: `xboard-app` and `xboard-theme`. BunkerWeb remains the public TCP 80/443 entrypoint through the existing `bw-services` network. The standalone Xboard-Admin project is not deployed.

The Xboard `master` workflow deploys immutable application and DK Theme digests through the GitHub `production` Environment. Ordinary mainline deployments preserve the SQLite database, uploads, themes, plugins, application `.env`, Redis state and runtime credentials. A fresh-data deployment is accepted only from a manual workflow dispatch with the boolean reset input and exact confirmation text `RESET JPGREEN XBOARD DATA`.

During a confirmed reset, existing Xboard runtime data is moved into a timestamped, mode-0700 backup below `/home/beihai/docker/xboard/backups` before a new database is installed. The bootstrap creates only the fixed administrator, `test@test.user`, the `Production Access` group and one revocable MCP Key. Servers and nodes are intentionally not created here; they are added later through the verified MCP connection.

The one-time MCP plaintext is written to `/home/beihai/docker/xboard/runtime-secrets/xboard_mcp_key` with mode 0600. It must be downloaded through SSH MCP into a local protected temporary file, imported through an environment-variable reference, and deleted on both machines immediately after protocol acceptance. Logs and Actions output must never print it.

The BunkerWeb helper adds one exact `/api/mcp` location. It disables ModSecurity only for that authenticated JSON-RPC endpoint, leaves all other paths under the panel service WAF, retains the existing path-specific request limit, updates the custom config through `Database.upsert_custom_config`, and validates the effective Nginx configuration. It does not restart the traffic-serving BunkerWeb container.

Required `production` Environment secrets:

- `PRODUCTION_SSH_PRIVATE_KEY`
- `PRODUCTION_SSH_KNOWN_HOSTS`
- `PRODUCTION_ADMIN_PASSWORD`
- `PRODUCTION_TEST_USER_PASSWORD`
- `PRODUCTION_SERVER_TOKEN`

Required `production` Environment variables:

- `PRODUCTION_SSH_HOST`
- `PRODUCTION_SSH_PORT`
- `PRODUCTION_SSH_USER`
- `PRODUCTION_PANEL_URL` (`https://panel.uegov.org`)
- `PRODUCTION_ADMIN_PATH` (`unitedearthgov`)
- `PRODUCTION_ADMIN_ACCOUNT` (`beihai3body@uegov.org`)
- `PRODUCTION_TEST_USER_EMAIL` (`test@test.user`)
- `PRODUCTION_DK_THEME_IMAGE` (immutable `ghcr.io/voidintheshell/dk_theme@sha256:...` reference)
