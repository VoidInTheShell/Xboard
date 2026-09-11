#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

CONTROL_DIR="/etc/xboard-ci"
LIBEXEC_DIR="/usr/local/libexec/xboard-ci"
PANEL_HOST="panel.uegov.org"
PANEL_URL="https://$PANEL_HOST"
TEST_USER_EMAIL="test@test.user"

fail() {
    printf '[xboard-ci-verify] ERROR: %s\n' "$*" >&2
    exit 1
}

# BunkerWeb performs a graceful worker reload immediately after its generated
# configuration changes. A just-retired HTTP/2 connection can occasionally
# report a framing error even though the replacement worker is healthy. These
# probes are idempotent (login, unauthenticated MCP ping, and an invalid
# machine credential), so retry only transport failures while preserving each
# exact HTTP status and JSON assertion below.
local_curl() {
    curl --silent --show-error --retry 4 --retry-all-errors --retry-delay 2 \
        --resolve "$PANEL_HOST:443:127.0.0.1" "$@"
}

[ "$(id -u)" = "0" ] || fail "verification must run as root"
[ -f "$CONTROL_DIR/secrets/test_user_password" ] || fail "test password is unavailable"
"$LIBEXEC_DIR/verify-theme.sh"
docker inspect xboard-app >/dev/null
docker exec bunkerweb-bunkerweb-1 sh -lc 'nginx -T 2>/dev/null | grep -q "^# XBOARD-MCP-COMPAT$"'
docker exec bunkerweb-bunkerweb-1 sh -lc 'nginx -T 2>/dev/null | grep -q "^# XBOARD-NODE-CONTROL-COMPAT$"'

request_file=$(mktemp)
response_file=$(mktemp)
mcp_response=$(mktemp)
node_response=$(mktemp)
cleanup() {
    rm -f -- "$request_file" "$response_file" "$mcp_response" "$node_response"
    unset TEST_USER_PASSWORD
}
trap cleanup EXIT

TEST_USER_PASSWORD=$(tr -d '\r\n' < "$CONTROL_DIR/secrets/test_user_password")
[ "${#TEST_USER_PASSWORD}" -ge 8 ] || fail "test password is invalid"
jq -n --arg email "$TEST_USER_EMAIL" --arg password "$TEST_USER_PASSWORD" \
    '{email: $email, password: $password}' > "$request_file"
unset TEST_USER_PASSWORD

login_status=$(local_curl --output "$response_file" --write-out '%{http_code}' \
    --header 'Content-Type: application/json' --data-binary "@$request_file" \
    "$PANEL_URL/api/v1/passport/auth/login")
[ "$login_status" = "200" ] || fail "public test-user login did not return HTTP 200"
jq -e '.data.auth_data | type == "string" and startswith("Bearer ")' "$response_file" >/dev/null

mcp_status=$(local_curl --output "$mcp_response" --write-out '%{http_code}' \
    --header 'Content-Type: application/json' \
    --data-binary '{"jsonrpc":"2.0","id":"host-probe","method":"ping","params":{}}' \
    "$PANEL_URL/api/mcp")
[ "$mcp_status" = "401" ] || fail "unauthenticated MCP probe did not return HTTP 401"

node_status=$(local_curl --output "$node_response" --write-out '%{http_code}' \
    --header 'Accept: application/json' --header 'Content-Type: application/json' \
    --data-binary '{"machine_id":2147483647,"token":"invalid-control-plane-probe"}' \
    "$PANEL_URL/api/v2/server/machine/nodes")
[ "$node_status" = "403" ] || fail "invalid machine probe did not reach the application auth boundary"
jq -e '.message == "Machine not found or disabled"' "$node_response" >/dev/null \
    || fail "machine API probe was intercepted before the JSON application boundary"

printf 'containers=xboard-app,xboard-theme,xboard-admin login=ok bunkerweb_mcp=active node_control=reachable public_entrypoints=ok\n'
