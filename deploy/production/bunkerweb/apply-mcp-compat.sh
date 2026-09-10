#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_FILE="${1:-}"
SERVICE_ID="panel.uegov.org"
CONFIG_NAME="xboard-mcp-compat"
SCHEDULER="bunkerweb-bw-scheduler-1"
BUNKERWEB="bunkerweb-bunkerweb-1"
DATABASE="bunkerweb-bw-db-1"
BACKUP_DIR="/home/beihai/docker/xboard/backups"

fail() {
    printf '[bunkerweb-mcp] ERROR: %s\n' "$*" >&2
    exit 1
}

[ -f "$CONFIG_FILE" ] || fail "configuration file is missing"
grep -q '^# XBOARD-MCP-COMPAT$' "$CONFIG_FILE" || fail "configuration marker is missing"
grep -q '^# XBOARD-NODE-CONTROL-COMPAT$' "$CONFIG_FILE" || fail "node control compatibility marker is missing"
[ "$(grep -Fc 'proxy_intercept_errors off;' "$CONFIG_FILE")" = "3" ] \
    || fail "JSON authentication responses must bypass the server error page"

for container in "$SCHEDULER" "$BUNKERWEB" "$DATABASE"; do
    status=$(docker inspect --format '{{.State.Status}}' "$container" 2>/dev/null || true)
    [ "$status" = "running" ] || fail "$container is not running"
done

checksum=$(sha256sum "$CONFIG_FILE" | cut -d' ' -f1)
current=$(docker exec -i "$DATABASE" sh -lc \
    'exec mariadb --batch --skip-column-names --raw -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<SQL
SELECT COALESCE(MAX(checksum), '') FROM bw_custom_configs WHERE service_id='${SERVICE_ID}' AND type='server_http' AND name='${CONFIG_NAME}' AND method='api' AND is_draft=0;
SQL
)
current=$(printf '%s' "$current" | tr -d '\r\n')

if [ "$current" != "$checksum" ]; then
    timestamp=$(date -u +%Y%m%dT%H%M%SZ)
    install -d -m 700 "$BACKUP_DIR"
    backup="$BACKUP_DIR/bunkerweb-before-mcp-${timestamp}.sql"
    docker exec "$DATABASE" sh -lc \
        'exec mariadb-dump --single-transaction --routines --triggers -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
        > "$backup"
    [ -s "$backup" ] || fail "BunkerWeb database backup is empty"
    chmod 600 "$backup"

    docker cp "$CONFIG_FILE" "$SCHEDULER:/tmp/xboard-mcp-compat.conf"
    docker exec "$SCHEDULER" sh -lc 'python3 - <<'"'"'PY'"'"'
import logging
from pathlib import Path
from Database import Database

path = Path("/tmp/xboard-mcp-compat.conf")
data = path.read_text(encoding="utf-8")
payload = {
    "service_id": "panel.uegov.org",
    "type": "server_http",
    "name": "xboard-mcp-compat",
    "data": data,
    "method": "api",
    "is_draft": False,
}
error = Database(logging.getLogger("xboard-mcp-compat")).upsert_custom_config(
    "server_http",
    "xboard-mcp-compat",
    payload,
    service_id="panel.uegov.org",
)
if error:
    raise SystemExit(error)
PY'
    docker exec -u 0 "$SCHEDULER" rm -f /tmp/xboard-mcp-compat.conf
fi

for _ in $(seq 1 45); do
    if docker exec "$BUNKERWEB" sh -lc 'nginx -T 2>/dev/null | grep -q "^# XBOARD-MCP-COMPAT$" && nginx -T 2>/dev/null | grep -q "^# XBOARD-NODE-CONTROL-COMPAT$"'; then
        docker exec "$BUNKERWEB" nginx -t >/dev/null
        printf '[bunkerweb-mcp] active checksum=%s\n' "$checksum"
        exit 0
    fi
    sleep 2
done

printf '[bunkerweb-mcp] scheduler did not materialize the change; restarting only the scheduler\n'
docker restart "$SCHEDULER" >/dev/null
for _ in $(seq 1 60); do
    if docker exec "$BUNKERWEB" sh -lc 'nginx -T 2>/dev/null | grep -q "^# XBOARD-MCP-COMPAT$" && nginx -T 2>/dev/null | grep -q "^# XBOARD-NODE-CONTROL-COMPAT$"'; then
        docker exec "$BUNKERWEB" nginx -t >/dev/null
        printf '[bunkerweb-mcp] active checksum=%s\n' "$checksum"
        exit 0
    fi
    sleep 2
done

fail "BunkerWeb did not load the MCP compatibility configuration"
