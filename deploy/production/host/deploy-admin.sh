#!/usr/bin/env bash
set -Eeuo pipefail

TARGET_DIR="/home/beihai/docker/xboard"
EXPECTED_TARGET="/home/beihai/docker/xboard"
GIT_SHA="${1:-}"
ADMIN_IMAGE="${2:-}"
REGISTRY_USER="${3:-}"

log() {
    printf '[production-admin] %s\n' "$*"
}

fail() {
    printf '[production-admin] ERROR: %s\n' "$*" >&2
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
    local status
    for _ in $(seq 1 45); do
        status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' xboard-admin 2>/dev/null || true)
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

[ "$(id -u)" = "0" ] || fail "this trusted deployment script must run as root"
[[ "$GIT_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "the release SHA is invalid"
[[ "$ADMIN_IMAGE" =~ ^ghcr\.io/voidintheshell/xboard-admin:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]] || fail "Admin image must use a version tag"
[[ "$REGISTRY_USER" =~ ^[A-Za-z0-9-]{1,39}$ ]] || fail "the registry user is invalid"
[ "$(realpath -m "$TARGET_DIR")" = "$EXPECTED_TARGET" ] || fail "unexpected target directory"
[ -f "$TARGET_DIR/compose.yaml" ] || fail "the production panel Compose file is not installed"
[ -f "$TARGET_DIR/.deploy.env" ] || fail "the production panel environment is not installed"
docker inspect xboard-app xboard-theme >/dev/null

IFS= read -r REGISTRY_TOKEN || true
[ -n "${REGISTRY_TOKEN:-}" ] || fail "registry token was not provided on stdin"

exec 9>"$TARGET_DIR/.deploy.lock"
flock -x 9
log "acquired production deployment lock"

AUTH_DIR=$(mktemp -d "/tmp/xboard-admin-production-auth.XXXXXX")
cleanup() {
    rm -rf -- "$AUTH_DIR"
    unset REGISTRY_TOKEN
}
trap cleanup EXIT

printf '%s\n' "$REGISTRY_TOKEN" | docker --config "$AUTH_DIR" login ghcr.io --username "$REGISTRY_USER" --password-stdin >/dev/null
unset REGISTRY_TOKEN
docker --config "$AUTH_DIR" pull "$ADMIN_IMAGE"

set_env_value "$TARGET_DIR/.deploy.env" "XBOARD_ADMIN_IMAGE" "$ADMIN_IMAGE"
compose() {
    docker compose --env-file "$TARGET_DIR/.deploy.env" -f "$TARGET_DIR/compose.yaml" "$@"
}

compose up -d --no-deps admin
if ! wait_for_healthy; then
    compose ps admin || true
    compose logs --tail 120 admin || true
    fail "standalone Admin container did not become healthy"
fi
docker exec xboard-admin wget -q -O /dev/null http://127.0.0.1/healthz
docker exec xboard-theme nginx -t >/dev/null
docker exec xboard-theme nginx -s reload >/dev/null
docker exec xboard-theme test -s /var/run/xboard-admin-route/active.conf
log "production standalone Admin deployment complete: $ADMIN_IMAGE"
compose ps admin
