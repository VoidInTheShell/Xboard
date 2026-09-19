# Default Xboard deployment with Docker Compose

The default compose.sample.yaml runs the Xboard backend, the standalone
Xboard-Admin frontend, a private updater, and a one-shot bootstrap service.
Theme is intentionally not part of this template; deployments that already
use DK Theme keep using their existing Theme stack.

The backend image, Admin image, and Updater image must be selected by exact
published version tags. XBOARD_ADMIN_VERSION and XBOARD_UPDATER_VERSION must
be identical. Do not use latest, a branch name, or a locally rebuilt image for
an installation that should be updateable from the panel.

## Prerequisites

- Docker Engine with the Compose plugin.
- An HTTPS URL for the panel API, used by the updater to report heartbeat and
  receive update tasks.
- The Xboard release manifest and the compatible Xboard-Admin release manifest.
  The Admin release supplies both the xboard-admin image and the
  xboard-admin-updater image from the same source version.

The updater needs the Docker socket because it replaces the selected Compose
service and performs recovery. The socket is equivalent to host-level Docker
control; keep the deployment directory, updater volumes, and host access
restricted to trusted administrators.

## First installation

Use a dedicated absolute directory. The same path must be visible on the host
and inside xboard-updater; this is required because the updater passes the
Compose file path to the host Docker daemon.

~~~bash
install -d -m 700 /opt/xboard
cd /opt/xboard

# Obtain these files from the exact Xboard release asset or a checked-out
# source tree at the release commit.
cp compose.sample.yaml compose.yaml
cp .env.example .env
~~~

Append the exact versions and deployment values to /opt/xboard/.env:

~~~dotenv
XBOARD_DEPLOY_DIR=/opt/xboard
XBOARD_VERSION=vX.Y.Z
XBOARD_ADMIN_VERSION=vA.B.C
XBOARD_UPDATER_VERSION=vA.B.C
XBOARD_PANEL_URL=https://panel.example.com
~~~

XBOARD_ADMIN_VERSION and XBOARD_UPDATER_VERSION must be the same exact Admin
release. Keep the normal Laravel database, application key, mail, and storage
settings in the same .env file. Never put a production token, private key, or
DNS credential in the repository.

Validate interpolation before starting anything:

~~~bash
./deploy/compose/validate.sh compose.yaml
docker compose --env-file .env -f compose.yaml config
~~~

Install the backend database, then start the complete stack:

~~~bash
docker compose --env-file .env -f compose.yaml run --rm \
  -e ENABLE_SQLITE=true \
  -e ENABLE_REDIS=true \
  -e ADMIN_ACCOUNT=admin@example.com \
  xboard php artisan xboard:install

docker compose --env-file .env -f compose.yaml up -d
docker compose --env-file .env -f compose.yaml ps
~~~

Startup order is part of the contract:

1. xboard becomes healthy.
2. updater-bootstrap provisions the panel executor through Artisan, writes the
   token with mode 0600, and generates the updater configuration and fixed
   backend hook in its private volumes.
3. xboard-admin starts after the backend health check.
4. xboard-updater starts only after bootstrap exits successfully.

The bootstrap is idempotent. Restarting or recreating the one-shot service does
not rotate a working executor credential. If the credential, configuration,
hook, or backend binding is incomplete, bootstrap provisions a replacement and
rewrites the private files.

The standalone Admin is available at http://SERVER_IP:7003 by default. The
backend remains available at port 7001 unless a reverse proxy is placed in
front of it. The updater has no published port.

## Persistence and updates

The template persists the backend .env, application data, logs, uploads,
themes, plugins, embedded Redis data, updater credentials, updater journal, and
database backup directory. Recreating containers does not remove these
resources.

Always preserve the generated Compose override in the updater state volume.
The updater uses it to keep the selected version of the service after a
subsequent manual up:

~~~bash
docker compose --env-file .env -f compose.yaml up -d
~~~

Do not use docker compose down --volumes during an update or recovery. If an
update fails, keep the updater journal, task directory, container snapshot, and
database backup for diagnosis and recovery.

The updater is intentionally limited to the xboard service in this default
template. It performs a maintenance-aware backend replacement, waits for
Supervisor and Redis readiness, runs the fixed migration/verification hooks,
and checks both the exact backend version and the configured health URL. Admin
and Updater releases are a single version unit in their own Admin workflow;
they are not independently selected in this Compose template.

## Advanced Compose split template

compose.split.sample.yaml remains available for deployments that deliberately
split the Web, Horizon, WebSocket, and Redis processes into separate services.
It is not a drop-in replacement for the default template: every writer must be
covered by an administrator-owned maintenance hook before enabling panel-managed
updates. Choose it only when the deployment has an explicit process ownership
and recovery design.

## Troubleshooting without destroying evidence

~~~bash
docker compose --env-file .env -f compose.yaml ps
docker compose --env-file .env -f compose.yaml logs --tail 200 xboard updater-bootstrap xboard-updater
docker volume ls --filter label=com.docker.compose.project=xboard
~~~

If bootstrap fails, keep the .env, Compose file, logs, and updater volumes.
Fix the reported configuration or backend installation issue, then rerun:

~~~bash
docker compose --env-file .env -f compose.yaml run --rm updater-bootstrap
docker compose --env-file .env -f compose.yaml up -d
~~~

Do not print or copy the token from the private updater volume. The panel
executor API accepts only that dedicated Bearer credential and the updater
reports its architecture, installation method, and local instance readiness
through the authenticated heartbeat endpoint.
