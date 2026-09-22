# Xboard staging deployment

This directory defines the shared Xboard test stack on GJHK. **CI deployment is suspended (since 2026-09-15):** the staging deploy job is hard-disabled with a `false &&` guard, so pushes and manual dispatches only build, test, and publish releases. GJHK is updated through the panel's Admin version-update UI (or MCP update tasks) by selecting an exact published version; the deploy assets in this directory are retained as the documented manual emergency/recovery path, and re-enabling CI deployment requires an explicit decision and a workflow change. Historically, pushes to `dev` deployed automatically and other branches deployed through `workflow_dispatch`; every deployment used prebuilt GHCR images and the server never compiled source code. The environment-topology details below (including the retired US2 node cover entrypoint) describe that historical staging baseline; the current GJHK installation (2026-09-22) is the four-service Compose stack defined by the repository's `compose.sample.yaml` plus the updater bootstrap.

Every branch push and pull request builds a CI-only image with Composer development dependencies and runs the PHP test suite. Published runtime images keep development dependencies excluded. A full stack rebuild consumes GHCR version tags for the backend, Theme, and standalone Admin. Each publish run adds a `build-<run_id>-<run_attempt>` tag, and the staging job uses that tag directly without looking up or rewriting image digests. Deployments verify the default standalone Admin, an actual secure-path change, the explicit built-in-panel fallback, and restoration of the default standalone entry without printing authentication tokens.

## Runtime layout

- Target directory: `/home/beihai/docker/xboard`
- Reverse proxy network: existing external Docker network `appnet`
- Public entrypoint: existing Nginx Proxy Manager host for `https://xboard.uegov.org`
- Backend/API and built-in administrator fallback frontend container: `xboard-app`, reachable only on the internal Docker network
- User frontend and public gateway container: `xboard-theme`, joined to both the internal network and `appnet`
- Standalone administrator frontend container: `xboard-admin`, reachable only on the internal Docker network
- Database: persistent SQLite under `data/`, initialized only on an empty first installation; a missing database in an existing installation is an error
- Cache/queue: the image's embedded Redis with a named Compose volume

The first deployment installs SQLite and the deterministic staging baseline. Later deployments preserve `data/`, `.env`, Redis and uploaded runtime state, create a consistent SQLite snapshot under `backups/`, apply forward migrations, and reject any release that reduces protected business-row counts. The shared staging host is not branch-isolated, so the latest successful deployment becomes the current test version. No production host or production database is part of this workflow.

Upgrades do not run the staging bootstrap again: administrator-managed servers, node bindings, users, counters and settings remain intact. Any failed image pull, migration, record guard or container health check restores the previous Compose manifest, image references, runtime credentials and database snapshot. Successful upgrades retain three marked release backups and their image references. Cleanup is limited to the four Xboard image repositories and unused volumes explicitly labelled `io.xboard.ephemeral=true`; persistent volumes and incident backups remain protected.

## GitHub environment

The workflow uses an environment named `staging`.

Environment secrets:

- `STAGING_SSH_PRIVATE_KEY`: dedicated deployment private key
- `STAGING_SSH_KNOWN_HOSTS`: pinned SSH host-key line for GJHK
- `STAGING_ADMIN_ACCOUNT`: staging administrator email (`beihai3body@uegov.org`)
- `STAGING_ADMIN_PASSWORD`: disposable administrator password
- `STAGING_TEST_USER_PASSWORD`: disposable `test@test.user` password
- `STAGING_SERVER_TOKEN`: shared panel/node communication token, at least 16 characters
- `STAGING_NODE_WS_PATH`: opaque VLESS WebSocket path; store the same value in the Xboard-Node staging Environment secret
- `STAGING_ADMIN_ROUTE_TOKEN`: at least 16 base64url-safe characters, used only between Theme and Xboard to synchronize the active administrator route

Environment variables:

- `STAGING_SSH_HOST`
- `STAGING_SSH_PORT`
- `STAGING_SSH_USER` (normally `beihai`)
- `STAGING_PANEL_URL` (normally `https://xboard.uegov.org`)
- `STAGING_ADMIN_PATH` (the validated secure path maintained by the staging bootstrap; it can later be changed in Xboard Admin)
- `STAGING_TEST_USER_EMAIL` (normally `test@test.user`)
- `STAGING_NODE_HOST`
- `STAGING_NODE_ID` (must remain `1` for the deterministic staging node)
- `STAGING_NODE_PUBLIC_PORT`
- `STAGING_NODE_LISTEN_PORT`
- `STAGING_DK_THEME_IMAGE`: published `ghcr.io/voidintheshell/dk_theme:<version-tag>` reference used for a full stack bootstrap or rebuild
- `STAGING_XBOARD_ADMIN_IMAGE`: published `ghcr.io/voidintheshell/xboard-admin:<version-tag>` reference used for a full stack bootstrap or rebuild

The same value stored as `STAGING_SERVER_TOKEN` here must be stored as `STAGING_API_KEY` in the Xboard-Node repository's `staging` environment. The same `STAGING_NODE_WS_PATH` secret must also be present in both repositories; neither value may be printed in workflow logs.

Both frontend image variables contain version-tagged references from their respective publish jobs, for example `ghcr.io/voidintheshell/xboard-admin:build-123456789-1`. Copy them from the successful run's `Report staging image tag` step or job summary; the tag includes the run attempt so rerunning a build has a distinct version. A `workflow_dispatch` full rebuild may override either variable with another published version tag, including an existing release tag such as `v1.2.3`. Refresh both variables after publishing frontend changes and before a full rebuild. The deployment stores the supplied tags unchanged and does not calculate or compare image SHA256 values.

The initial database creates node `1` as VLESS over WebSocket, a dedicated `Staging Access` server group, and the staging test user assigned to that group. Later deployments update that named baseline without deleting manually added servers, machines or native inbound configuration. Its public endpoint is the DNS hostname on port `443`; TLS is terminated by the US2 reverse-proxy/cover entrypoint, while Xboard-Node listens without TLS on the private `STAGING_NODE_LISTEN_PORT`. This split is represented by the fork-specific `protocol_settings.server_tls` field so client subscriptions keep TLS enabled without requiring the node process to own port 443.

Do not publish the staging node DNS record until the US2 reverse proxy and cover site are healthy. The panel record can exist first because the disposable database is rebuilt independently from the node host.

## Safety and recovery

The remote script hard-checks the target and incoming bundle paths, then takes `/home/beihai/docker/xboard/.deploy.lock`. Before a migration it stops the App, creates a WAL-safe SQLite snapshot, and compares protected table counts after bootstrap. A regression restores the verified pre-deploy snapshot and leaves the Actions run red without printing externally supplied administrator credentials.

The historical branch-dispatch deployment path is suspended with the deploy guard; staging updates now go through the Admin version-update UI. Do not replace the image manually over SSH. To inspect the server without changing it:

```bash
cd /home/beihai/docker/xboard
sudo docker compose --env-file .deploy.env -f compose.yaml ps
sudo docker compose --env-file .deploy.env -f compose.yaml logs --tail 200 xboard theme
```
