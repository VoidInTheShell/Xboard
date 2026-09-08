# Xboard production deployment

This directory defines the JPGREEN production panel. The persistent runtime is exactly two containers: `xboard-app` and `xboard-theme`. BunkerWeb remains the public TCP 80/443 entrypoint through the existing `bw-services` network. The standalone Xboard-Admin project is not deployed.

The Xboard `master` workflow deploys immutable application and DK Theme digests through the GitHub `production` Environment. It connects as a dedicated `xboard-ci` SSH principal whose key is bound to a root-owned forced-command dispatcher. The account is not a Docker-group member and cannot open a general shell, upload arbitrary executables, forward ports or run arbitrary sudo commands.

Ordinary mainline deployments preserve the SQLite database, uploads, themes, plugins, application `.env`, Redis state and runtime credentials. A fresh-data deployment requires a manual workflow dispatch, the boolean reset input, the exact confirmation text `RESET JPGREEN XBOARD DATA`, and a separate root-only authorization file at `/etc/xboard-ci/reset-authorizations/<master-sha>`. The dispatcher consumes that file only for the corresponding SHA and restores it when deployment fails, so possession of the CI key alone cannot erase production data.

During a confirmed reset, existing Xboard runtime data is moved into a timestamped, mode-0700 backup below `/home/beihai/docker/xboard/backups` before a new database is installed. The bootstrap creates only the fixed administrator, `test@test.user`, the `Production Access` group and one revocable MCP Key. Servers and nodes are intentionally not created here; they are added later through the verified MCP connection.

The one-time MCP plaintext is written to `/home/beihai/docker/xboard/runtime-secrets/xboard_mcp_key` with mode 0600. It must be downloaded through SSH MCP into a local protected temporary file, imported through an environment-variable reference, and deleted on both machines immediately after protocol acceptance. Logs and Actions output must never print it.

The BunkerWeb helper adds one exact `/api/mcp` location. It disables ModSecurity only for that authenticated JSON-RPC endpoint, leaves all other paths under the panel service WAF, retains the existing path-specific request limit, updates the custom config through `Database.upsert_custom_config`, and validates the effective Nginx configuration. It does not restart the traffic-serving BunkerWeb container.

After a theme container replacement, the trusted deployer validates the generated Nginx configuration and performs a graceful BunkerWeb reload so its upstream address immediately follows the new container IP. Production acceptance runs through the restricted host verifier with real `panel.uegov.org` TLS/SNI directed at the local BunkerWeb listener. This keeps the service's `WHITELIST_COUNTRY=CN` policy intact instead of opening all dynamic GitHub-hosted runner addresses, while still verifying health, PNG MIME/content, the built-in administrator shell, test-user login and the unauthenticated MCP boundary.

Production bootstrap passwords and the server token stay in root-owned mode-0600 files below `/etc/xboard-ci/secrets`; they are not stored in GitHub. A workflow passes only its run-scoped `GITHUB_TOKEN` over SSH stdin for the immediate GHCR pull, and the deployer removes its temporary Docker authentication directory on exit.

Required `production` Environment secrets:

- `PRODUCTION_DEPLOY_SSH_KEY`
- `PRODUCTION_SSH_KNOWN_HOSTS`

Required `production` Environment variables:

- `PRODUCTION_SSH_HOST`
- `PRODUCTION_SSH_PORT`
- `PRODUCTION_SSH_USER`
- `PRODUCTION_PANEL_URL` (`https://panel.uegov.org`)
- `PRODUCTION_ADMIN_PATH` (`unitedearthgov`)
- `PRODUCTION_DK_THEME_IMAGE` (immutable `ghcr.io/voidintheshell/dk_theme@sha256:...` reference)

`PRODUCTION_SSH_USER` must be `xboard-ci`. Provisioning with `host/install-ci-deployer.sh` is an explicit administrator action; ordinary workflows cannot replace the root-owned dispatcher, deploy scripts or verification script.
