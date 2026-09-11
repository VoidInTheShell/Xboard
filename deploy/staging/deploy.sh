#!/usr/bin/env bash
set -Eeuo pipefail

TARGET_DIR="/home/beihai/docker/xboard"
EXPECTED_TARGET="/home/beihai/docker/xboard"
BUNDLE_DIR="${1:-}"
REGISTRY_USER="${2:-}"

log() {
    printf '[staging-deploy] %s\n' "$*"
}

fail() {
    printf '[staging-deploy] ERROR: %s\n' "$*" >&2
    exit 1
}

require_file() {
    [ -f "$1" ] || fail "required file is missing: $1"
}

wait_for_healthy() {
    local container="$1"
    local status
    for _ in $(seq 1 90); do
        status=$(sudo -n docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container" 2>/dev/null || true)
        if [ "$status" = "healthy" ] || [ "$status" = "running" ]; then
            log "$container is $status"
            return 0
        fi
        if [ "$status" = "unhealthy" ] || [ "$status" = "exited" ] || [ "$status" = "dead" ]; then
            return 1
        fi
        sleep 2
    done
    return 1
}

is_tagged_ghcr_image() {
    local image="$1"
    local repository="$2"
    [[ "$image" =~ ^ghcr\.io/voidintheshell/${repository}:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]]
}

[ -n "$BUNDLE_DIR" ] || fail "bundle directory argument is required"
[ -n "$REGISTRY_USER" ] || fail "registry user argument is required"

RESOLVED_TARGET=$(realpath -m "$TARGET_DIR")
[ "$RESOLVED_TARGET" = "$EXPECTED_TARGET" ] || fail "refusing unexpected target: $RESOLVED_TARGET"

RESOLVED_BUNDLE=$(realpath -e "$BUNDLE_DIR")
case "$RESOLVED_BUNDLE" in
    /home/beihai/docker/.incoming/xboard-*) ;;
    *) fail "refusing unexpected bundle path: $RESOLVED_BUNDLE" ;;
esac

require_file "$RESOLVED_BUNDLE/compose.yaml"
require_file "$RESOLVED_BUNDLE/deploy.env"
require_file "$RESOLVED_BUNDLE/admin_password"
require_file "$RESOLVED_BUNDLE/server_token"
require_file "$RESOLVED_BUNDLE/test_user_password"
require_file "$RESOLVED_BUNDLE/admin_route_token"

XBOARD_IMAGE=$(sed -n 's/^XBOARD_IMAGE=//p' "$RESOLVED_BUNDLE/deploy.env" | tail -1)
DK_THEME_IMAGE=$(sed -n 's/^DK_THEME_IMAGE=//p' "$RESOLVED_BUNDLE/deploy.env" | tail -1)
XBOARD_ADMIN_IMAGE=$(sed -n 's/^XBOARD_ADMIN_IMAGE=//p' "$RESOLVED_BUNDLE/deploy.env" | tail -1)
is_tagged_ghcr_image "$XBOARD_IMAGE" xboard || fail "XBOARD_IMAGE must be an xboard GHCR image with a version tag"
is_tagged_ghcr_image "$DK_THEME_IMAGE" dk_theme || fail "DK_THEME_IMAGE must be a DK Theme GHCR image with a version tag"
is_tagged_ghcr_image "$XBOARD_ADMIN_IMAGE" xboard-admin || fail "XBOARD_ADMIN_IMAGE must be an xboard-admin GHCR image with a version tag"

IFS= read -r REGISTRY_TOKEN || true
[ -n "${REGISTRY_TOKEN:-}" ] || fail "registry token was not provided on stdin"

install -d -m 750 "$TARGET_DIR"
exec 9>"$TARGET_DIR/.deploy.lock"
flock -x 9
log "acquired deployment lock"

if [ -f "$TARGET_DIR/compose.yaml" ]; then
    log "stopping the previous test stack"
    if [ -f "$TARGET_DIR/.deploy.env" ]; then
        sudo -n docker compose --env-file "$TARGET_DIR/.deploy.env" -f "$TARGET_DIR/compose.yaml" down --volumes --remove-orphans || true
    else
        sudo -n docker compose -f "$TARGET_DIR/compose.yaml" down --volumes --remove-orphans || true
    fi
fi

for runtime_name in data logs plugins themes uploads secrets backups theme-dist; do
    runtime_path=$(realpath -m "$TARGET_DIR/$runtime_name")
    case "$runtime_path" in
        "$EXPECTED_TARGET"/*) sudo -n rm -rf -- "$runtime_path" ;;
        *) fail "refusing to remove unexpected runtime path: $runtime_path" ;;
    esac
done

for runtime_file in .env install-secrets.txt admin-path.txt nginx.conf nginx.conf.template theme-index.html; do
    file_path=$(realpath -m "$TARGET_DIR/$runtime_file")
    case "$file_path" in
        "$EXPECTED_TARGET"/*) sudo -n rm -f -- "$file_path" ;;
        *) fail "refusing to remove unexpected runtime file: $file_path" ;;
    esac
done

find "$TARGET_DIR" -maxdepth 1 -type f -name 'dk-theme-*-production.tar.gz' -delete

sudo -n chown -R beihai:beihai "$TARGET_DIR"
install -d -m 700 "$TARGET_DIR/secrets"
install -d -m 755 "$TARGET_DIR/data" "$TARGET_DIR/logs" "$TARGET_DIR/plugins" "$TARGET_DIR/themes" "$TARGET_DIR/uploads"
install -m 644 "$RESOLVED_BUNDLE/compose.yaml" "$TARGET_DIR/compose.yaml"
install -m 600 "$RESOLVED_BUNDLE/deploy.env" "$TARGET_DIR/.deploy.env"
install -m 600 "$RESOLVED_BUNDLE/admin_password" "$TARGET_DIR/secrets/admin_password"
install -m 600 "$RESOLVED_BUNDLE/server_token" "$TARGET_DIR/secrets/server_token"
install -m 600 "$RESOLVED_BUNDLE/test_user_password" "$TARGET_DIR/secrets/test_user_password"
install -m 600 "$RESOLVED_BUNDLE/admin_route_token" "$TARGET_DIR/secrets/admin_route_token"
# The application worker runs as UID/GID 1000. Docker file secrets preserve
# this source file's ownership and mode, so expose this token only to that
# service group; other bootstrap secrets stay private to the host user/root.
sudo -n chown 0:1000 "$TARGET_DIR/secrets/admin_route_token"
sudo -n chmod 640 "$TARGET_DIR/secrets/admin_route_token"
install -m 600 /dev/null "$TARGET_DIR/.env"

AUTH_DIR=$(mktemp -d "/tmp/xboard-docker-auth.XXXXXX")
ANON_DIR=$(mktemp -d "/tmp/xboard-docker-anon.XXXXXX")
cleanup() {
    sudo -n rm -rf -- "$AUTH_DIR" "$ANON_DIR"
    unset REGISTRY_TOKEN
}
trap cleanup EXIT

printf '%s\n' "$REGISTRY_TOKEN" | sudo -n docker --config "$AUTH_DIR" login ghcr.io --username "$REGISTRY_USER" --password-stdin >/dev/null 2>&1 \
    || fail "registry authentication failed"
unset REGISTRY_TOKEN

log "pulling panel image: $XBOARD_IMAGE"
sudo -n docker --config "$AUTH_DIR" pull "$XBOARD_IMAGE" >/dev/null 2>&1 || fail "could not pull the Xboard version tag"
log "pulling theme image: $DK_THEME_IMAGE"
sudo -n docker --config "$ANON_DIR" pull "$DK_THEME_IMAGE" >/dev/null 2>&1 || fail "could not pull the Theme version tag"
log "pulling standalone admin image: $XBOARD_ADMIN_IMAGE"
sudo -n docker --config "$AUTH_DIR" pull "$XBOARD_ADMIN_IMAGE" >/dev/null 2>&1 || fail "could not pull the standalone Admin version tag"

compose() {
    sudo -n docker compose --env-file "$TARGET_DIR/.deploy.env" -f "$TARGET_DIR/compose.yaml" "$@"
}

log "installing a fresh SQLite staging database"
compose run --rm bootstrap php artisan xboard:install --no-interaction

log "starting the panel so its embedded Redis socket is available"
compose up -d xboard
if ! wait_for_healthy xboard-app; then
    compose ps || true
    compose logs --tail 160 xboard || true
    fail "the staging panel did not become healthy before bootstrap"
fi
sudo -n docker exec xboard-app test -S /data/redis.sock || fail "the embedded Redis socket is not available"

log "creating the deterministic staging node record"
compose run --rm bootstrap php artisan xboard:staging-bootstrap --no-interaction

log "restarting the panel to load the new settings, then starting the standalone admin"
compose restart xboard
if ! wait_for_healthy xboard-app; then
    compose ps || true
    compose logs --tail 160 xboard || true
    fail "the staging panel did not become healthy after bootstrap"
fi
compose up -d admin
if ! wait_for_healthy xboard-admin; then
    compose ps || true
    compose logs --tail 160 admin || true
    fail "the standalone admin did not become healthy"
fi
compose up -d theme

if ! wait_for_healthy xboard-theme; then
    compose ps || true
    compose logs --tail 160 xboard theme || true
    fail "one or more staging containers did not become healthy"
fi

sudo -n docker exec xboard-app wget -q -O /dev/null http://127.0.0.1:7001/
sudo -n docker exec xboard-admin wget -q -O /dev/null http://127.0.0.1/healthz
sudo -n docker exec xboard-theme wget -q -O /dev/null http://127.0.0.1/healthz
sudo -n docker exec xboard-theme test -s /var/run/xboard-admin-route/active.conf
sudo -n docker image prune -f >/dev/null

log "deployment complete"
compose ps
