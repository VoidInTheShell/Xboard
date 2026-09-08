#!/usr/bin/env bash
set -Eeuo pipefail

TARGET_DIR="/home/beihai/docker/xboard"
EXPECTED_TARGET="/home/beihai/docker/xboard"
BUNDLE_DIR="${1:-}"
REGISTRY_USER="${2:-}"
RESET_MODE="${3:-preserve}"

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

set_env_value() {
    local file="$1"
    local key="$2"
    local value="$3"
    local temp_file
    temp_file=$(mktemp "${file}.XXXXXX")
    awk -v key="$key" -v value="$value" '
        BEGIN { found = 0 }
        index($0, key "=") == 1 { print key "=" value; found = 1; next }
        { print }
        END { if (!found) print key "=" value }
    ' "$file" > "$temp_file"
    install -m 600 "$temp_file" "$file"
    rm -f "$temp_file"
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

is_immutable_ghcr_image() {
    local image="$1"
    local repository="$2"
    [[ "$image" =~ ^ghcr\.io/voidintheshell/${repository}@sha256:[0-9a-f]{64}$ ]]
}

[ -n "$BUNDLE_DIR" ] || fail "bundle directory argument is required"
[ -n "$REGISTRY_USER" ] || fail "registry user argument is required"
case "$RESET_MODE" in
    preserve|reset) ;;
    *) fail "reset mode must be preserve or reset" ;;
esac

RESOLVED_TARGET=$(realpath -m "$TARGET_DIR")
[ "$RESOLVED_TARGET" = "$EXPECTED_TARGET" ] || fail "refusing unexpected target: $RESOLVED_TARGET"
RESOLVED_BUNDLE=$(realpath -e "$BUNDLE_DIR")
case "$RESOLVED_BUNDLE" in
    /home/beihai/docker/.incoming/xboard-production-*) ;;
    *) fail "refusing unexpected bundle path: $RESOLVED_BUNDLE" ;;
esac

for file in compose.yaml deploy.env production-bootstrap.php xboard-mcp.conf apply-mcp-compat.sh admin_password server_token test_user_password; do
    require_file "$RESOLVED_BUNDLE/$file"
done

IFS= read -r REGISTRY_TOKEN || true
[ -n "${REGISTRY_TOKEN:-}" ] || fail "registry token was not provided on stdin"

install -d -m 750 "$TARGET_DIR" "$TARGET_DIR/backups"
exec 9>"$TARGET_DIR/.deploy.lock"
flock -x 9
log "acquired production deployment lock"

timestamp=$(date -u +%Y%m%dT%H%M%SZ)
release_backup="$TARGET_DIR/backups/release-${timestamp}"
install -d -m 700 "$release_backup"
CURRENT_THEME_IMAGE=""
if [ -f "$TARGET_DIR/.deploy.env" ]; then
    CURRENT_THEME_IMAGE=$(sed -n 's/^DK_THEME_IMAGE=//p' "$TARGET_DIR/.deploy.env" | tail -1)
    if [ -n "$CURRENT_THEME_IMAGE" ]; then
        is_immutable_ghcr_image "$CURRENT_THEME_IMAGE" dk_theme || fail "the installed DK_THEME_IMAGE is not an immutable GHCR digest"
    fi
fi
for item in compose.yaml .deploy.env deploy.sh; do
    [ ! -e "$TARGET_DIR/$item" ] || cp -a "$TARGET_DIR/$item" "$release_backup/$item"
done

install -d -m 700 "$TARGET_DIR/secrets" "$TARGET_DIR/bootstrap" "$TARGET_DIR/runtime-secrets" "$TARGET_DIR/bunkerweb"
install -m 644 "$RESOLVED_BUNDLE/compose.yaml" "$TARGET_DIR/compose.yaml"
install -m 600 "$RESOLVED_BUNDLE/deploy.env" "$TARGET_DIR/.deploy.env"
install -m 700 "$RESOLVED_BUNDLE/apply-mcp-compat.sh" "$TARGET_DIR/bunkerweb/apply-mcp-compat.sh"
install -m 644 "$RESOLVED_BUNDLE/xboard-mcp.conf" "$TARGET_DIR/bunkerweb/xboard-mcp.conf"
install -m 644 "$RESOLVED_BUNDLE/production-bootstrap.php" "$TARGET_DIR/bootstrap/production-bootstrap.php"
install -m 600 "$RESOLVED_BUNDLE/admin_password" "$TARGET_DIR/secrets/admin_password"
install -m 600 "$RESOLVED_BUNDLE/server_token" "$TARGET_DIR/secrets/server_token"
install -m 600 "$RESOLVED_BUNDLE/test_user_password" "$TARGET_DIR/secrets/test_user_password"
install -m 700 "$RESOLVED_BUNDLE/deploy.sh" "$TARGET_DIR/deploy.sh"

if [ -n "$CURRENT_THEME_IMAGE" ]; then
    set_env_value "$TARGET_DIR/.deploy.env" "DK_THEME_IMAGE" "$CURRENT_THEME_IMAGE"
fi

XBOARD_IMAGE=$(sed -n 's/^XBOARD_IMAGE=//p' "$TARGET_DIR/.deploy.env" | tail -1)
DK_THEME_IMAGE=$(sed -n 's/^DK_THEME_IMAGE=//p' "$TARGET_DIR/.deploy.env" | tail -1)
is_immutable_ghcr_image "$XBOARD_IMAGE" xboard || fail "XBOARD_IMAGE must be an immutable xboard GHCR digest"
is_immutable_ghcr_image "$DK_THEME_IMAGE" dk_theme || fail "DK_THEME_IMAGE must be an immutable DK Theme GHCR digest"

AUTH_DIR=$(mktemp -d "/tmp/xboard-production-auth.XXXXXX")
cleanup() {
    sudo -n rm -rf -- "$AUTH_DIR"
    unset REGISTRY_TOKEN
}
trap cleanup EXIT
printf '%s\n' "$REGISTRY_TOKEN" | sudo -n docker --config "$AUTH_DIR" login ghcr.io --username "$REGISTRY_USER" --password-stdin >/dev/null
unset REGISTRY_TOKEN

log "pulling immutable application and theme images"
sudo -n docker --config "$AUTH_DIR" pull "$XBOARD_IMAGE"
sudo -n docker --config "$AUTH_DIR" pull "$DK_THEME_IMAGE"

compose() {
    sudo -n docker compose --env-file "$TARGET_DIR/.deploy.env" -f "$TARGET_DIR/compose.yaml" "$@"
}

if [ "$RESET_MODE" = "reset" ]; then
    log "performing the explicitly confirmed fresh-data deployment"
    compose down --remove-orphans || true
    data_backup="$TARGET_DIR/backups/data-reset-${timestamp}"
    install -d -m 700 "$data_backup"
    for item in .env data logs uploads themes plugins redis runtime-secrets; do
        path=$(realpath -m "$TARGET_DIR/$item")
        case "$path" in
            "$EXPECTED_TARGET"/*) ;;
            *) fail "refusing unexpected runtime path: $path" ;;
        esac
        [ ! -e "$path" ] || mv "$path" "$data_backup/$item"
    done
    install -d -m 755 "$TARGET_DIR/data" "$TARGET_DIR/logs" "$TARGET_DIR/uploads" "$TARGET_DIR/themes" "$TARGET_DIR/plugins" "$TARGET_DIR/redis"
    install -d -m 700 "$TARGET_DIR/runtime-secrets"
    install -m 600 /dev/null "$TARGET_DIR/.env"

    compose run --rm bootstrap php artisan xboard:install --no-interaction
    compose up -d xboard
    wait_for_healthy xboard-app || fail "xboard-app did not become healthy after installation"
    sudo -n docker exec xboard-app test -S /data/redis.sock || fail "the embedded Redis socket is unavailable"
    compose run --rm bootstrap php /bootstrap/production-bootstrap.php
    compose restart xboard
else
    require_file "$TARGET_DIR/.env"
    require_file "$TARGET_DIR/data/database.sqlite"
    log "preserving production data and applying forward migrations"
    compose stop xboard || true
    database_backup="$release_backup/database.sqlite"
    cp -a "$TARGET_DIR/data/database.sqlite" "$database_backup"
    chmod 600 "$database_backup"
    compose run --rm --no-deps bootstrap php artisan migrate --force
    compose up -d xboard
fi

if ! wait_for_healthy xboard-app; then
    compose ps || true
    compose logs --tail 180 xboard || true
    fail "xboard-app did not become healthy"
fi

compose up -d theme
if ! wait_for_healthy xboard-theme; then
    compose ps || true
    compose logs --tail 180 xboard theme || true
    fail "xboard-theme did not become healthy"
fi

sudo -n docker exec xboard-app wget -q -O /dev/null http://127.0.0.1:7001/
sudo -n docker exec xboard-theme wget -q -O /dev/null http://127.0.0.1/healthz
"$TARGET_DIR/bunkerweb/apply-mcp-compat.sh" "$TARGET_DIR/bunkerweb/xboard-mcp.conf"

log "production deployment complete"
compose ps
