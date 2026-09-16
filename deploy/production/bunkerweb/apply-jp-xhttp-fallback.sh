#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_FILE="${1:-}"
SERVICE_ID="jp-blog.sacbridge.dpdns.org"
CONFIG_NAME="xboard-xhttp"
SCHEDULER="bunkerweb-bw-scheduler-1"
BUNKERWEB="bunkerweb-bunkerweb-1"
DATABASE="bunkerweb-bw-db-1"
BACKUP_DIR="/home/beihai/docker/xboard/backups"
MARKER="# XBOARD-JP-XHTTP-FALLBACK"
SCHEDULER_TEMP="/tmp/xboard-jp-xhttp-fallback.conf"
updated=0
workdir=""

fail() {
    printf '[bunkerweb-jp-xhttp] ERROR: %s\n' "$*" >&2
    exit 1
}

[ -f "$CONFIG_FILE" ] || fail "fallback configuration file is missing"
grep -Fqx "$MARKER" "$CONFIG_FILE" || fail "fallback configuration marker is missing"

for container in "$SCHEDULER" "$BUNKERWEB" "$DATABASE"; do
    status=$(docker inspect --format '{{.State.Status}}' "$container" 2>/dev/null || true)
    [ "$status" = "running" ] || fail "$container is not running"
done

workdir=$(mktemp -d /tmp/xboard-jp-xhttp.XXXXXX)
case "$workdir" in
    /tmp/xboard-jp-xhttp.*) ;;
    *) fail "unexpected temporary directory" ;;
esac

cleanup() {
    rm -rf -- "$workdir"
    docker exec -u 0 "$SCHEDULER" rm -f "$SCHEDULER_TEMP" >/dev/null 2>&1 || true
}

normalize_for_compare() {
    # mariadb's raw row terminator can add one terminal blank line to `data`.
    # Preserve all nonterminal content and interior blank lines while removing
    # only that representation difference for content-identity checks.
    awk '
        {
            line = $0
            sub(/\r$/, "", line)
            if (line ~ /^[[:space:]]*$/) {
                blanks++
                next
            }
            if (printed) {
                while (blanks-- > 0) print ""
            }
            blanks = 0
            print line
            printed = 1
        }
    ' "$1"
}

upsert_from_file() {
    local source="$1"

    docker cp "$source" "$SCHEDULER:$SCHEDULER_TEMP"
    docker exec "$SCHEDULER" sh -lc 'python3 - <<'"'"'PY'"'"'
import logging
from pathlib import Path
from Database import Database

path = Path("/tmp/xboard-jp-xhttp-fallback.conf")
data = path.read_text(encoding="utf-8")
payload = {
    "service_id": "jp-blog.sacbridge.dpdns.org",
    "type": "server_http",
    "name": "xboard-xhttp",
    "data": data,
    "method": "api",
    "is_draft": False,
}
error = Database(logging.getLogger("xboard-jp-xhttp")).upsert_custom_config(
    "server_http",
    "xboard-xhttp",
    payload,
    service_id="jp-blog.sacbridge.dpdns.org",
)
if error:
    raise SystemExit(error)
PY'
    docker exec -u 0 "$SCHEDULER" rm -f "$SCHEDULER_TEMP"
}

restore_original() {
    [ "$updated" = "1" ] || return 0
    printf '[bunkerweb-jp-xhttp] restoring previous custom configuration\n' >&2
    set +e
    upsert_from_file "$current"
    for _ in $(seq 1 25); do
        if ! docker exec "$BUNKERWEB" sh -lc "nginx -T 2>/dev/null | grep -Fqx '$MARKER'"; then
            docker exec "$BUNKERWEB" nginx -t >/dev/null
            docker exec "$BUNKERWEB" nginx -s reload >/dev/null
            break
        fi
        sleep 2
    done
    set -e
}

on_exit() {
    rc=$?
    trap - EXIT
    if [ "$rc" -ne 0 ]; then
        restore_original || true
    fi
    cleanup
    exit "$rc"
}
trap on_exit EXIT

current="$workdir/current.conf"
next="$workdir/next.conf"
existing="$workdir/existing-fallback.conf"
legacy="$workdir/legacy-fallback.conf"
base="$workdir/base.conf"
desired_normalized="$workdir/desired-normalized.conf"
existing_normalized="$workdir/existing-normalized.conf"
legacy_normalized="$workdir/legacy-normalized.conf"

records=$(docker exec -i "$DATABASE" sh -lc \
    'exec mariadb --batch --skip-column-names --raw -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<SQL
SELECT COUNT(*)
FROM bw_custom_configs
WHERE service_id='${SERVICE_ID}'
  AND type='server_http'
  AND name='${CONFIG_NAME}'
  AND method='api'
  AND is_draft=0;
SQL
)
records=$(printf '%s' "$records" | tr -d '\r\n')
[ "$records" = "1" ] || fail "expected exactly one active XHTTP custom configuration"

docker exec -i "$DATABASE" sh -lc \
    'exec mariadb --batch --skip-column-names --raw -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' <<SQL > "$current"
SELECT data
FROM bw_custom_configs
WHERE service_id='${SERVICE_ID}'
  AND type='server_http'
  AND name='${CONFIG_NAME}'
  AND method='api'
  AND is_draft=0;
SQL

[ -s "$current" ] || fail "expected xboard XHTTP custom configuration is missing"

if grep -Fqx "$MARKER" "$current"; then
    [ "$(grep -Fxc "$MARKER" "$current")" = "1" ] \
        || fail "existing fallback marker is not unique"
    awk -v marker="$MARKER" '$0 == marker {copy=1} copy {print}' "$current" > "$existing"
    normalize_for_compare "$existing" > "$existing_normalized"
    normalize_for_compare "$CONFIG_FILE" > "$desired_normalized"

    if cmp -s "$existing_normalized" "$desired_normalized"; then
        docker exec "$BUNKERWEB" nginx -t >/dev/null
        docker exec "$BUNKERWEB" sh -lc "nginx -T 2>/dev/null | grep -Fqx '$MARKER'" \
            || fail "existing fallback marker is not active"
        printf '[bunkerweb-jp-xhttp] fallback already active\n'
        exit 0
    fi

    # Only migrate the known first revision, which differed solely by the
    # verified TLS depth line (after normalizing the DB row terminator). Any
    # other administrator-owned change stops here rather than being overwritten
    # or duplicated.
    sed '/^[[:space:]]*proxy_ssl_verify_depth 5;[[:space:]]*$/d' "$CONFIG_FILE" > "$legacy"
    normalize_for_compare "$legacy" > "$legacy_normalized"
    cmp -s "$existing_normalized" "$legacy_normalized" \
        || fail "existing fallback differs from the known migration source"
    awk -v marker="$MARKER" '$0 == marker {exit} {print}' "$current" > "$base"
    {
        cat "$base"
        printf '\n'
        cat "$CONFIG_FILE"
    } > "$next"
else
    {
        cat "$current"
        printf '\n'
        cat "$CONFIG_FILE"
    } > "$next"
fi

[ "$(grep -Fxc "$MARKER" "$next")" = "1" ] || fail "fallback marker is not unique"

timestamp=$(date -u +%Y%m%dT%H%M%SZ)
install -d -m 700 "$BACKUP_DIR"
backup="$BACKUP_DIR/bunkerweb-before-jp-xhttp-${timestamp}.sql"
umask 077
docker exec "$DATABASE" sh -lc \
    'exec mariadb-dump --single-transaction --routines --triggers -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
    > "$backup"
[ -s "$backup" ] || fail "BunkerWeb database backup is empty"
chmod 600 "$backup"

updated=1
upsert_from_file "$next"

for _ in $(seq 1 25); do
    if docker exec "$BUNKERWEB" sh -lc "nginx -T 2>/dev/null | grep -Fqx '$MARKER'"; then
        docker exec "$BUNKERWEB" nginx -t >/dev/null
        docker exec "$BUNKERWEB" nginx -s reload >/dev/null
        updated=0
        printf '[bunkerweb-jp-xhttp] fallback active\n'
        exit 0
    fi
    sleep 2
done

fail "BunkerWeb did not materialize the fallback configuration"
