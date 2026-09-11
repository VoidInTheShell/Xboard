# Xboard production deployment

This directory defines the JPGREEN production panel. The persistent runtime is exactly three containers: `xboard-app`, `xboard-theme`, and `xboard-admin`. BunkerWeb remains the public TCP 80/443 entrypoint through the existing `bw-services` network. Only `xboard-theme` joins that public service network; Xboard and the standalone Admin remain on the internal Compose network.

The Xboard `master` workflow performs production deployment only when manually dispatched with `deployment_target=production` through the GitHub `production` Environment. A manual dispatch with `deployment_target=staging` is mutually exclusive and cannot reach JPGREEN. Production deploys its run-specific `build-<run_id>-<run_attempt>` Xboard image tag together with explicitly selected, version-tagged DK Theme and Xboard-Admin images. The workflow connects as a dedicated `xboard-ci` SSH principal whose key is bound to a root-owned forced-command dispatcher. The account is not a Docker-group member and cannot open a general shell, upload arbitrary executables, forward ports or run arbitrary sudo commands.

The current Xboard secure path always serves standalone Xboard-Admin. Theme learns that path only through a Docker secret and renders a precise internal Nginx route: `/<current-path>/` goes to `xboard-admin`, while `/<current-path>/original` is the explicit fallback to the built-in Xboard administrator view. Changing the secure path in standalone Admin is therefore an immediately effective route change, not a return to the built-in view.

Ordinary mainline deployments preserve the SQLite database, uploads, themes, plugins, application `.env`, Redis state and runtime credentials. A fresh-data deployment requires a manual workflow dispatch, the boolean reset input, the exact confirmation text `RESET JPGREEN XBOARD DATA`, and a separate root-only authorization file at `/etc/xboard-ci/reset-authorizations/<master-sha>`. The dispatcher consumes that file only for the corresponding SHA and restores it when deployment fails, so possession of the CI key alone cannot erase production data.

During a confirmed reset, existing Xboard runtime data is moved into a timestamped, mode-0700 backup below `/home/beihai/docker/xboard/backups` before a new database is installed. The bootstrap creates only the fixed administrator, `test@test.user`, the `Production Access` group and one revocable MCP Key. Servers and nodes are intentionally not created here; they are added later through the verified MCP connection.

The one-time MCP plaintext is written to `/home/beihai/docker/xboard/runtime-secrets/xboard_mcp_key` with mode 0600. It is downloaded through SSH MCP into a protected temporary file, written to the explicitly approved local Codex `config.toml` authorization header, and then deleted from both temporary locations after protocol acceptance. The Codex configuration ACL is restricted to the local user and SYSTEM. Logs and Actions output must never print the key.

The BunkerWeb helper adds one exact `/api/mcp` location plus narrow `/api/v1/server/` and `/api/v2/server/` prefixes for Xboard's authenticated node control plane. Those payloads carry opaque tokens, UUIDs and telemetry that the stock CRS misclassifies. ModSecurity is disabled only in those three scopes, and `proxy_intercept_errors off` preserves Xboard's JSON authentication response instead of transforming it into BunkerWeb's HTML error page. User, administrator and theme paths retain the service-wide WAF. The helper updates the custom config through `Database.upsert_custom_config`, validates the effective Nginx configuration, and does not restart the traffic-serving BunkerWeb container.

After a theme container replacement, the trusted deployer validates the generated Nginx configuration and performs a graceful BunkerWeb reload so its upstream address immediately follows the new container IP. Production acceptance runs through the restricted host verifier with real `panel.uegov.org` TLS/SNI directed at the local BunkerWeb listener. The service-level country whitelist is currently disabled by the operator; the verifier checks all three container health states, the dynamic standalone entry, the explicit original-panel fallback, PNG MIME/content, test-user login, the unauthenticated MCP boundary, and an invalid machine request reaching Xboard's JSON authentication boundary instead of a BunkerWeb HTML 403.

Production bootstrap passwords, the server token, and the Admin route token stay in root-owned mode-0600 files below `/etc/xboard-ci/secrets`; they are not stored in GitHub. The installer creates the route token with the host cryptographic RNG only if it does not already exist. The deployer copies only the route token into the Compose runtime as `root:1000` mode `0640`, which is the least privilege needed by Xboard's UID/GID-1000 Octane worker; its control-plane source remains root-only. A workflow passes only its run-scoped `GITHUB_TOKEN` over SSH stdin for the immediate GHCR pull, and the deployer removes its temporary Docker authentication directory on exit.

Required `production` Environment secrets:

- `PRODUCTION_DEPLOY_SSH_KEY`
- `PRODUCTION_SSH_KNOWN_HOSTS`

Required `production` Environment variables:

- `PRODUCTION_SSH_HOST`
- `PRODUCTION_SSH_PORT`
- `PRODUCTION_SSH_USER`
- `PRODUCTION_PANEL_URL` (`https://panel.uegov.org`)
- `PRODUCTION_ADMIN_PATH` (`unitedearthgov`)
- `PRODUCTION_DK_THEME_IMAGE` (version-tagged `ghcr.io/voidintheshell/dk_theme:<tag>` reference)
- `PRODUCTION_XBOARD_ADMIN_IMAGE` (version-tagged `ghcr.io/voidintheshell/xboard-admin:<tag>` reference)

`PRODUCTION_SSH_USER` must be `xboard-ci`. The image inputs accept only the fixed GHCR repository plus a legal Docker version tag; deployment never resolves, computes, or compares image SHA256/digest values. Provisioning with `host/install-ci-deployer.sh` is an explicit administrator action; ordinary workflows cannot replace the root-owned dispatcher, deploy scripts or verification script. A later standalone Admin release is promoted by an explicit Xboard production dispatch with its version tag, so the full three-container compatibility gate remains available.
