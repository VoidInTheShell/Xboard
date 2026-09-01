# Xboard staging deployment

This directory defines the disposable Xboard test stack on GJHK. It is deployed only from the public fork's `master` branch and always uses prebuilt GHCR images. The server never compiles source code.

## Runtime layout

- Target directory: `/home/beihai/docker/xboard`
- Reverse proxy network: existing external Docker network `appnet`
- Public entrypoint: existing Nginx Proxy Manager host for `https://xboard.uegov.org`
- Panel container: `xboard-app`, reachable only on the internal Docker network
- Theme container: `xboard-theme`, joined to both the internal network and `appnet`
- Database: disposable SQLite under `data/`
- Cache/queue: the image's embedded Redis with a named Compose volume

Every successful `master` deployment stops the old Compose project, removes only this service's containers, named volume and explicit runtime subdirectories, then performs a fresh install. No production host or production database is part of this workflow.

## GitHub environment

The workflow uses an environment named `staging`.

Environment secrets:

- `STAGING_SSH_PRIVATE_KEY`: dedicated deployment private key
- `STAGING_SSH_KNOWN_HOSTS`: pinned SSH host-key line for GJHK
- `STAGING_ADMIN_ACCOUNT`: staging administrator email (`beihai3body@uegov.org`)
- `STAGING_ADMIN_PASSWORD`: disposable administrator password
- `STAGING_SERVER_TOKEN`: shared panel/node communication token, at least 16 characters

Environment variables:

- `STAGING_SSH_HOST`
- `STAGING_SSH_PORT`
- `STAGING_SSH_USER` (normally `beihai`)
- `STAGING_PANEL_URL` (normally `https://xboard.uegov.org`)
- `STAGING_ADMIN_PATH` (exactly eight lowercase hexadecimal characters; kept stable across disposable rebuilds)
- `STAGING_NODE_HOST`
- `STAGING_NODE_ID` (must remain `1` for the fresh database)
- `STAGING_NODE_PUBLIC_PORT`
- `STAGING_NODE_LISTEN_PORT`
- `STAGING_NODE_WS_PATH`

The same value stored as `STAGING_SERVER_TOKEN` here must be stored as `STAGING_API_KEY` in the Xboard-Node repository's `staging` environment.

The fresh database creates node `1` as VLESS over WebSocket. Its public endpoint is the DNS hostname on port `443`; TLS is terminated by the US2 reverse-proxy/cover entrypoint, while Xboard-Node listens without TLS on the private `STAGING_NODE_LISTEN_PORT`. This split is represented by the fork-specific `protocol_settings.server_tls` field so client subscriptions keep TLS enabled without requiring the node process to own port 443.

Do not publish the staging node DNS record until the US2 reverse proxy and cover site are healthy. The panel record can exist first because the disposable database is rebuilt independently from the node host.

## Safety and recovery

The remote script hard-checks the target and incoming bundle paths, then takes `/home/beihai/docker/xboard/.deploy.lock`. A failed install leaves the Actions run red and includes container status/log tails without printing externally supplied administrator credentials.

To redeploy the current `master` manually, run the `Docker Build, Publish and Deploy` workflow with `workflow_dispatch`. To inspect the server without changing it:

```bash
cd /home/beihai/docker/xboard
sudo docker compose --env-file .deploy.env -f compose.yaml ps
sudo docker compose --env-file .deploy.env -f compose.yaml logs --tail 200 xboard theme
```
