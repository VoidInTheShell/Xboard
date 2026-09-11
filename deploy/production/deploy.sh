#!/usr/bin/env bash
set -Eeuo pipefail

TARGET_DIR="/home/beihai/docker/xboard"
EXPECTED_TARGET="/home/beihai/docker/xboard"
CONTROL_DIR="/etc/xboard-ci"
ASSET_DIR="/usr/local/libexec/xboard-ci/assets"
XBOARD_REPOSITORY="https://github.com/VoidInTheShell/Xboard.git"
BUNKERWEB="bunkerweb-bunkerweb-1"
PANEL_HOST="panel.uegov.org"

GIT_SHA="${1:-}"
XBOARD_IMAGE="${2:-}"
REQUESTED_THEME_IMAGE="${3:-}"
REQUESTED_ADMIN_IMAGE="${4:-}"
REGISTRY_USER="${5:-}"
RESET_MODE="${6:-preserve}"

log() {
    printf '[production-deploy] %s\n' "$*"
}

fail() {
    printf '[production-deploy] ERROR: %s\n' "$*" >&2
    exit 1
}

require_file() {
    [ -f "$1" ] || fail "required file is missing: $1"
}

branch_sha() {
    local repository="$1"
    local branch="$2"
    git ls-remote --exit-code --refs "$repository" "refs/heads/$branch" | awk 'NR == 1 { print $1 }'
}

wait_for_healthy() {
    local container="$1"
    local status
    for _ in $(seq 1 90); do
        status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container" 2>/dev/null || true)
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

refresh_bunkerweb_upstream() {
    [ "$(docker inspect --format '{{.State.Status}}' "$BUNKERWEB" 2>/dev/null || true)" = "running" ] || fail "$BUNKERWEB is not running"
    docker exec "$BUNKERWEB" nginx -t >/dev/null
    docker exec "$BUNKERWEB" nginx -s reload >/dev/null
    for _ in $(seq 1 30); do
        if curl --fail --silent --show-error --resolve "$PANEL_HOST:443:127.0.0.1" "https://$PANEL_HOST/healthz" >/dev/null; then
            log "BunkerWeb resolved the current theme upstream"
            return 0
        fi
        sleep 2
    done
    fail "BunkerWeb did not converge on the current theme upstream"
}

is_tagged_ghcr_image() {
    local image="$1"
    local repository="$2"
    [[ "$image" =~ ^ghcr\.io/voidintheshell/${repository}:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]]
}

[ "$(id -u)" = "0" ] || fail "this trusted deployment script must run as root"
[[ "$GIT_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "the release SHA is invalid"
is_tagged_ghcr_image "$XBOARD_IMAGE" xboard || fail "the Xboard image must use a version tag"
is_tagged_ghcr_image "$REQUESTED_THEME_IMAGE" dk_theme || fail "the Theme image must use a version tag"
is_tagged_ghcr_image "$REQUESTED_ADMIN_IMAGE" xboard-admin || fail "the Xboard Admin image must use a version tag"
[[ "$REGISTRY_USER" =~ ^[A-Za-z0-9-]{1,39}$ ]] || fail "the registry user is invalid"
case "$RESET_MODE" in
    preserve|reset) ;;
    *) fail "reset mode must be preserve or reset" ;;
esac

CURRENT_XBOARD_SHA=$(branch_sha "$XBOARD_REPOSITORY" master)
[ "$GIT_SHA" = "$CURRENT_XBOARD_SHA" ] || fail "release SHA is not the current Xboard master"

[ "$(realpath -m "$TARGET_DIR")" = "$EXPECTED_TARGET" ] || fail "unexpected production target"
for file in compose.yaml production-bootstrap.php sqlite-data-guard.php xboard-mcp.conf apply-mcp-compat.sh; do
    require_file "$ASSET_DIR/$file"
done
for secret in admin_password server_token test_user_password admin_route_token; do
    require_file "$CONTROL_DIR/secrets/$secret"
    [ "$(stat -c '%U:%G:%a' "$CONTROL_DIR/secrets/$secret")" = "root:root:600" ] || fail "$secret permissions are not root:root:600"
done

IFS= read -r REGISTRY_TOKEN || true
[ -n "${REGISTRY_TOKEN:-}" ] || fail "registry token was not provided on stdin"

AUTH_DIR=$(mktemp -d "/tmp/xboard-production-auth.XXXXXX")
cleanup() {
    rm -rf -- "$AUTH_DIR"
    unset REGISTRY_TOKEN
}
trap cleanup EXIT
printf '%s\n' "$REGISTRY_TOKEN" | docker --config "$AUTH_DIR" login ghcr.io --username "$REGISTRY_USER" --password-stdin >/dev/null 2>&1 \
    || fail "registry authentication failed"
unset REGISTRY_TOKEN

log "pulling version-tagged application, Theme and standalone Admin images"
docker --config "$AUTH_DIR" pull "$XBOARD_IMAGE" >/dev/null 2>&1 || fail "could not pull the Xboard version tag"
docker --config "$AUTH_DIR" pull "$REQUESTED_THEME_IMAGE" >/dev/null 2>&1 || fail "could not pull the Theme version tag"
docker --config "$AUTH_DIR" pull "$REQUESTED_ADMIN_IMAGE" >/dev/null 2>&1 || fail "could not pull the standalone Admin version tag"

install -o root -g root -d -m 750 "$TARGET_DIR" "$TARGET_DIR/backups"
exec 9>"$TARGET_DIR/.deploy.lock"
flock -x 9
log "acquired production deployment lock"

timestamp=$(date -u +%Y%m%dT%H%M%SZ)
release_backup="$TARGET_DIR/backups/release-${timestamp}"
install -o root -g root -d -m 700 "$release_backup"
for item in compose.yaml .deploy.env deploy.sh; do
    [ ! -e "$TARGET_DIR/$item" ] || cp -a "$TARGET_DIR/$item" "$release_backup/$item"
done

install -o root -g root -d -m 700 "$TARGET_DIR/secrets" "$TARGET_DIR/bootstrap" "$TARGET_DIR/runtime-secrets" "$TARGET_DIR/bunkerweb"
install -o root -g root -m 644 "$ASSET_DIR/compose.yaml" "$TARGET_DIR/compose.yaml"
install -o root -g root -m 700 "$0" "$TARGET_DIR/deploy.sh"
install -o root -g root -m 700 "$ASSET_DIR/apply-mcp-compat.sh" "$TARGET_DIR/bunkerweb/apply-mcp-compat.sh"
install -o root -g root -m 644 "$ASSET_DIR/xboard-mcp.conf" "$TARGET_DIR/bunkerweb/xboard-mcp.conf"
install -o root -g root -m 644 "$ASSET_DIR/production-bootstrap.php" "$TARGET_DIR/bootstrap/production-bootstrap.php"
install -o root -g root -m 644 "$ASSET_DIR/sqlite-data-guard.php" "$TARGET_DIR/bootstrap/sqlite-data-guard.php"
for secret in admin_password server_token test_user_password; do
    install -o root -g root -m 600 "$CONTROL_DIR/secrets/$secret" "$TARGET_DIR/secrets/$secret"
done
install -o root -g root -m 600 "$CONTROL_DIR/secrets/admin_route_token" "$TARGET_DIR/secrets/admin_route_token"
# Docker file secrets retain the source file mode. Octane runs as UID/GID 1000,
# so grant only that service group read access to the route token. The control
# copy remains root-only above, and the non-privileged host user is not in GID
# 1000.
chown 0:1000 "$TARGET_DIR/secrets/admin_route_token"
chmod 640 "$TARGET_DIR/secrets/admin_route_token"

DK_THEME_IMAGE="$REQUESTED_THEME_IMAGE"
XBOARD_ADMIN_IMAGE="$REQUESTED_ADMIN_IMAGE"
{
    printf 'XBOARD_IMAGE=%s\n' "$XBOARD_IMAGE"
    printf 'DK_THEME_IMAGE=%s\n' "$DK_THEME_IMAGE"
    printf 'XBOARD_ADMIN_IMAGE=%s\n' "$XBOARD_ADMIN_IMAGE"
    printf 'PRODUCTION_PANEL_URL=https://panel.uegov.org\n'
    printf 'PRODUCTION_ADMIN_ACCOUNT=beihai3body@uegov.org\n'
    printf 'PRODUCTION_TEST_USER_EMAIL=test@test.user\n'
} > "$TARGET_DIR/.deploy.env"
chmod 600 "$TARGET_DIR/.deploy.env"

compose() {
    docker compose --env-file "$TARGET_DIR/.deploy.env" -f "$TARGET_DIR/compose.yaml" "$@"
}

restore_previous_runtime_files() {
    for item in compose.yaml .deploy.env deploy.sh; do
        [ ! -f "$release_backup/$item" ] || cp -a "$release_backup/$item" "$TARGET_DIR/$item"
    done
}

rollback_preserve_deployment() {
    local database_backup="$1"
    compose run --rm --no-deps bootstrap php /bootstrap/sqlite-data-guard.php restore "$database_backup" /www/.docker/.data/database.sqlite || true
    restore_previous_runtime_files
    compose up -d xboard || true
}

if [ "$RESET_MODE" = "reset" ]; then
    log "performing the separately armed fresh-data deployment"
    compose down --remove-orphans || true
    data_backup="$TARGET_DIR/backups/data-reset-${timestamp}"
    install -o root -g root -d -m 700 "$data_backup"
    for item in .env data logs uploads themes plugins redis runtime-secrets; do
        path=$(realpath -m "$TARGET_DIR/$item")
        case "$path" in
            "$EXPECTED_TARGET"/*) ;;
            *) fail "refusing unexpected runtime path: $path" ;;
        esac
        [ ! -e "$path" ] || mv "$path" "$data_backup/$item"
    done
    install -d -m 755 "$TARGET_DIR/data" "$TARGET_DIR/logs" "$TARGET_DIR/uploads" "$TARGET_DIR/themes" "$TARGET_DIR/plugins" "$TARGET_DIR/redis"
    install -o root -g root -d -m 700 "$TARGET_DIR/runtime-secrets"
    install -o root -g root -m 600 /dev/null "$TARGET_DIR/.env"

    compose run --rm bootstrap php artisan xboard:install --no-interaction
    compose up -d xboard
    wait_for_healthy xboard-app || fail "xboard-app did not become healthy after installation"
    docker exec xboard-app test -S /data/redis.sock || fail "the embedded Redis socket is unavailable"
    compose run --rm bootstrap php /bootstrap/production-bootstrap.php
    compose restart xboard
else
    require_file "$TARGET_DIR/.env"
    require_file "$TARGET_DIR/data/database.sqlite"
    log "preserving production data and applying forward migrations"
    compose stop xboard || true
    database_backup="/backups/release-${timestamp}/database.sqlite"
    if ! compose run --rm --no-deps bootstrap php /bootstrap/sqlite-data-guard.php snapshot /www/.docker/.data/database.sqlite "$database_backup"; then
        restore_previous_runtime_files
        compose up -d xboard || true
        fail "could not create a consistent production database snapshot"
    fi
    if ! compose run --rm --no-deps bootstrap php artisan migrate --force; then
        rollback_preserve_deployment "$database_backup"
        fail "production migration failed; the pre-deploy database and runtime files were restored"
    fi
    if ! compose run --rm --no-deps bootstrap php /bootstrap/sqlite-data-guard.php assert-not-decreased "$database_backup" /www/.docker/.data/database.sqlite; then
        rollback_preserve_deployment "$database_backup"
        fail "protected production business records decreased; the pre-deploy database and runtime files were restored"
    fi
    compose up -d xboard
fi

if ! wait_for_healthy xboard-app; then
    compose ps || true
    compose logs --tail 180 xboard || true
    fail "xboard-app did not become healthy"
fi

compose up -d admin
if ! wait_for_healthy xboard-admin; then
    compose ps || true
    compose logs --tail 180 xboard admin || true
    fail "xboard-admin did not become healthy"
fi

compose up -d theme
if ! wait_for_healthy xboard-theme; then
    compose ps || true
    compose logs --tail 180 xboard theme || true
    fail "xboard-theme did not become healthy"
fi

docker exec xboard-app wget -q -O /dev/null http://127.0.0.1:7001/
docker exec xboard-admin wget -q -O /dev/null http://127.0.0.1/healthz
docker exec xboard-theme wget -q -O /dev/null http://127.0.0.1/healthz
docker exec xboard-theme test -s /var/run/xboard-admin-route/active.conf
"$TARGET_DIR/bunkerweb/apply-mcp-compat.sh" "$TARGET_DIR/bunkerweb/xboard-mcp.conf"
refresh_bunkerweb_upstream

log "production deployment complete"
compose ps
