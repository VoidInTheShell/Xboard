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
docker inspect xboard-theme "$BUNKERWEB" >/dev/null
docker exec xboard-theme wget -q -O /dev/null http://127.0.0.1/healthz
docker exec "$BUNKERWEB" nginx -t >/dev/null
! docker ps -a --format '{{.Names}}' | grep -qx xboard-admin || fail "standalone admin must not run in production"

logo_file=$(mktemp)
admin_page=$(mktemp)
cleanup() {
    rm -f -- "$logo_file" "$admin_page"
}
trap cleanup EXIT

public_curl "$PANEL_URL/healthz" >/dev/null
logo_type=$(public_curl --output "$logo_file" --write-out '%{content_type}' "$PANEL_URL/dk-theme/ueg-mark.png")
[ -s "$logo_file" ] || fail "the public theme logo is empty"
case "$logo_type" in
    image/png*) ;;
    *) fail "the public theme logo has an unexpected content type" ;;
esac
public_curl "$PANEL_URL/unitedearthgov" > "$admin_page"
grep -Fq '<title>XBoard</title>' "$admin_page" || fail "the built-in administrator title is missing"
grep -Fq '/assets/admin/' "$admin_page" || fail "the built-in administrator assets are missing"

printf 'container=xboard-theme health=ok bunkerweb=ok public_theme=ok admin=ok isolation=ok\n'
