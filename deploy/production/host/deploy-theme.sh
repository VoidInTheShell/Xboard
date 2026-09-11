#!/usr/bin/env bash
set -Eeuo pipefail

TARGET_DIR="/home/beihai/docker/xboard"
EXPECTED_TARGET="/home/beihai/docker/xboard"
BUNKERWEB="bunkerweb-bunkerweb-1"
PANEL_HOST="panel.uegov.org"
GIT_SHA="${1:-}"
THEME_IMAGE="${2:-}"
REGISTRY_USER="${3:-}"

log() {
    printf '[production-theme] %s\n' "$*"
}

fail() {
    printf '[production-theme] ERROR: %s\n' "$*" >&2
    exit 1
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
    install -o root -g root -m 600 "$temp_file" "$file"
    rm -f "$temp_file"
}

wait_for_healthy() {
    local container="$1"
    local status
    for _ in $(seq 1 45); do
        status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container" 2>/dev/null || true)
        if [ "$status" = "healthy" ] || [ "$status" = "running" ]; then
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
            return 0
        fi
        sleep 2
    done
    fail "BunkerWeb did not converge on the current theme upstream"
}

[ "$(id -u)" = "0" ] || fail "this trusted deployment script must run as root"
[[ "$GIT_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "the release SHA is invalid"
[[ "$THEME_IMAGE" =~ ^ghcr\.io/voidintheshell/dk_theme:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]] || fail "theme image must use a version tag"
[[ "$REGISTRY_USER" =~ ^[A-Za-z0-9-]{1,39}$ ]] || fail "the registry user is invalid"
[ "$(realpath -m "$TARGET_DIR")" = "$EXPECTED_TARGET" ] || fail "unexpected target directory"
[ -f "$TARGET_DIR/compose.yaml" ] || fail "the production panel Compose file is not installed"
[ -f "$TARGET_DIR/.deploy.env" ] || fail "the production panel environment is not installed"
docker inspect xboard-app xboard-admin >/dev/null

IFS= read -r REGISTRY_TOKEN || true
[ -n "${REGISTRY_TOKEN:-}" ] || fail "registry token was not provided on stdin"

exec 9>"$TARGET_DIR/.deploy.lock"
flock -x 9
log "acquired production deployment lock"

AUTH_DIR=$(mktemp -d "/tmp/dk-theme-production-auth.XXXXXX")
cleanup() {
    rm -rf -- "$AUTH_DIR"
    unset REGISTRY_TOKEN
}
trap cleanup EXIT

printf '%s\n' "$REGISTRY_TOKEN" | docker --config "$AUTH_DIR" login ghcr.io --username "$REGISTRY_USER" --password-stdin >/dev/null 2>&1 \
    || fail "registry authentication failed"
unset REGISTRY_TOKEN
docker --config "$AUTH_DIR" pull "$THEME_IMAGE" >/dev/null 2>&1 || fail "could not pull the Theme version tag"

set_env_value "$TARGET_DIR/.deploy.env" "DK_THEME_IMAGE" "$THEME_IMAGE"
compose() {
    docker compose --env-file "$TARGET_DIR/.deploy.env" -f "$TARGET_DIR/compose.yaml" "$@"
}

compose up -d --no-deps theme
if ! wait_for_healthy xboard-theme; then
    compose ps theme || true
    compose logs --tail 120 theme || true
    fail "theme container did not become healthy"
fi
docker exec xboard-theme wget -q -O /dev/null http://127.0.0.1/healthz
docker exec xboard-theme test -s /var/run/xboard-admin-route/active.conf
refresh_bunkerweb_upstream
log "production Theme deployment complete: $THEME_IMAGE"
compose ps theme
