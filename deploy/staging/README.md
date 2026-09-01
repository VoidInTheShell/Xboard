# Xboard staging deployment

This directory defines the disposable Xboard test stack on GJHK. Pushes to the integration branches `main`, `master`, `dev`, and `new-dev`, plus opt-in `staging/**` branches, deploy automatically. Any other branch can deploy only through `workflow_dispatch` selected on that branch. Every deployment uses prebuilt GHCR images; the server never compiles source code.

Every branch push and pull request builds a CI-only image with Composer development dependencies and runs the PHP test suite. Published runtime images keep development dependencies excluded. Deployments also verify that the disposable test user can log in through the public API without printing the returned authentication token.

## Runtime layout

- Target directory: `/home/beihai/docker/xboard`
- Reverse proxy network: existing external Docker network `appnet`
- Public entrypoint: existing Nginx Proxy Manager host for `https://xboard.uegov.org`
- Panel container: `xboard-app`, reachable only on the internal Docker network
- Theme container: `xboard-theme`, joined to both the internal network and `appnet`
- Database: disposable SQLite under `data/`
- Cache/queue: the image's embedded Redis with a named Compose volume

Every successful staging deployment stops the old Compose project, removes only this service's containers, named volume and explicit runtime subdirectories, then performs a fresh install. The shared staging host is not branch-isolated, so the latest successful deployment becomes the current test version. No production host or production database is part of this workflow.

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

Environment variables:

- `STAGING_SSH_HOST`
- `STAGING_SSH_PORT`
- `STAGING_SSH_USER` (normally `beihai`)
- `STAGING_PANEL_URL` (normally `https://xboard.uegov.org`)
- `STAGING_ADMIN_PATH` (fixed to `unitedearthgov`, matching the project-wide `/unitedearthgov` administrator entrypoint)
- `STAGING_TEST_USER_EMAIL` (normally `test@test.user`)
- `STAGING_NODE_HOST`
- `STAGING_NODE_ID` (must remain `1` for the fresh database)
- `STAGING_NODE_PUBLIC_PORT`
- `STAGING_NODE_LISTEN_PORT`

The same value stored as `STAGING_SERVER_TOKEN` here must be stored as `STAGING_API_KEY` in the Xboard-Node repository's `staging` environment. The same `STAGING_NODE_WS_PATH` secret must also be present in both repositories; neither value may be printed in workflow logs.

The fresh database creates node `1` as VLESS over WebSocket, a dedicated `Staging Access` server group, and the disposable test user assigned to that group. Its public endpoint is the DNS hostname on port `443`; TLS is terminated by the US2 reverse-proxy/cover entrypoint, while Xboard-Node listens without TLS on the private `STAGING_NODE_LISTEN_PORT`. This split is represented by the fork-specific `protocol_settings.server_tls` field so client subscriptions keep TLS enabled without requiring the node process to own port 443.

Do not publish the staging node DNS record until the US2 reverse proxy and cover site are healthy. The panel record can exist first because the disposable database is rebuilt independently from the node host.

## Safety and recovery

The remote script hard-checks the target and incoming bundle paths, then takes `/home/beihai/docker/xboard/.deploy.lock`. A failed install leaves the Actions run red and includes container status/log tails without printing externally supplied administrator credentials.

To deploy a feature branch, open `Docker Build, Publish and Deploy`, choose **Run workflow**, select that branch, and run it. Do not replace the image manually over SSH. To inspect the server without changing it:

```bash
cd /home/beihai/docker/xboard
sudo docker compose --env-file .deploy.env -f compose.yaml ps
sudo docker compose --env-file .deploy.env -f compose.yaml logs --tail 200 xboard theme
```
