#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PANEL_HOST="panel.uegov.org"
PANEL_URL="https://$PANEL_HOST"
BUNKERWEB="bunkerweb-bunkerweb-1"

fail() {
    printf '[xboard-ci-theme-verify] ERROR: %s\n' "$*" >&2
    exit 1
}

public_curl() {
    curl --fail --silent --show-error --retry 12 --retry-all-errors --retry-delay 2 \
        --resolve "$PANEL_HOST:443:127.0.0.1" "$@"
}

[ "$(id -u)" = "0" ] || fail "verification must run as root"
docker inspect xboard-theme xboard-admin "$BUNKERWEB" >/dev/null
docker exec xboard-theme wget -q -O /dev/null http://127.0.0.1/healthz
docker exec xboard-admin wget -q -O /dev/null http://127.0.0.1/healthz
docker exec "$BUNKERWEB" nginx -t >/dev/null
docker exec xboard-theme test -s /var/run/xboard-admin-route/active.conf
active_path=$(docker exec xboard-theme cat /var/run/xboard-admin-route/active-path)
[[ "$active_path" =~ ^[A-Za-z0-9_-]{8,}$ && "$active_path" != "passport" ]] || fail "active administrator path is invalid"

logo_file=$(mktemp)
admin_page=$(mktemp)
original_admin_page=$(mktemp)
cleanup() {
    rm -f -- "$logo_file" "$admin_page" "$original_admin_page"
}
trap cleanup EXIT

public_curl "$PANEL_URL/healthz" >/dev/null
logo_type=$(public_curl --output "$logo_file" --write-out '%{content_type}' "$PANEL_URL/dk-theme/ueg-mark.png")
[ -s "$logo_file" ] || fail "the public theme logo is empty"
case "$logo_type" in
    image/png*) ;;
    *) fail "the public theme logo has an unexpected content type" ;;
esac
public_curl "$PANEL_URL/${active_path}/" > "$admin_page"
grep -Fq '<title>XBoard Admin</title>' "$admin_page" || fail "the standalone administrator title is missing"
grep -Fq 'data-xboard-admin-shell="standalone"' "$admin_page" || fail "the standalone administrator marker is missing"
grep -Fq './assets/' "$admin_page" || fail "the standalone administrator relative assets are missing"
public_curl "$PANEL_URL/${active_path}/original" > "$original_admin_page"
grep -Fq '<title>XBoard</title>' "$original_admin_page" || fail "the built-in administrator title is missing"
grep -Fq '/assets/admin/' "$original_admin_page" || fail "the built-in administrator assets are missing"
! grep -Fq 'data-xboard-admin-shell="standalone"' "$original_admin_page" || fail "the fallback unexpectedly served the standalone administrator"

printf 'containers=xboard-app,xboard-theme,xboard-admin health=ok bunkerweb=ok public_theme=ok standalone_admin=ok original_fallback=ok\n'
