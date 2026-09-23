#!/usr/bin/env bash
# Xboard panel one-command deployment.
#
# Deploys the full Compose suite with the Caddy HTTPS entry:
#   xboard (backend) + DK_Theme (user panel) + xboard-admin (admin UI)
#   + xboard-updater (update executor / panel-cert reconciler) + xboard-entry
#
# Usage:
#   sudo ./install.sh --domain panel.example.com
#   sudo ./install.sh --domain panel.example.com --email you@example.com \
#        --admin-email admin@example.com
#
# Options:
#   --domain FQDN        Panel domain; DNS must point at this server and
#                        ports 80/443 must be reachable for ACME.
#   --email ADDR         ACME account email for the entry certificate.
#   --admin-email ADDR   Initial administrator account (default: derived
#                        interactive prompt).
#   --admin-password PW  Initial administrator password (default: generated
#                        random password, printed once).
#   --admin-path PATH    Administrator secure path (default: random 8 chars).
#   --deploy-dir DIR     Deployment directory (default: /opt/xboard).
#   --channel NAME       Release channel: stable (default) or dev.
#   --version TAG        Deploy an exact published version; overrides
#                        --channel.
#   --no-entry           Skip the Caddy entry (bind an own reverse proxy to
#                        the published theme port 7002 instead).
#   --test-user          Internal: also create the standard test user.
#   --force              Continue even when the deploy dir already exists.
#   -h | --help          Show this help.
#
# The script is idempotent for a fresh directory only. Redeployments and
# upgrades are performed from the Admin panel (version updates), never by
# running this script again over an installation.

set -euo pipefail

DEFAULT_RELEASE_VERSION="latest"

REPO="VoidInTheShell/Xboard"
GH_BASE="https://github.com/${REPO}"
API_BASE="https://api.github.com/repos/${REPO}"

log()  { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWARN:\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

usage() { sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

DOMAIN=""
ACME_EMAIL=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
ADMIN_PATH=""
DEPLOY_DIR="/opt/xboard"
CHANNEL="stable"
VERSION=""
ENTRY=1
TEST_USER=0
FORCE=0

while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift 2 ;;
    --email) ACME_EMAIL="${2:-}"; shift 2 ;;
    --admin-email) ADMIN_EMAIL="${2:-}"; shift 2 ;;
    --admin-password) ADMIN_PASSWORD="${2:-}"; shift 2 ;;
    --admin-path) ADMIN_PATH="${2:-}"; shift 2 ;;
    --deploy-dir) DEPLOY_DIR="${2:-}"; shift 2 ;;
    --channel) CHANNEL="${2:-}"; shift 2 ;;
    --version) VERSION="${2:-}"; shift 2 ;;
    --no-entry) ENTRY=0; shift ;;
    --test-user) TEST_USER=1; shift ;;
    --force) FORCE=1; shift ;;
    -h|--help) usage 0 ;;
    *) die "unknown option: $1 (see --help)" ;;
  esac
done

[ "$(id -u)" -eq 0 ] || die "run as root (sudo)."
[ "$(uname -s)" = "Linux" ] || die "this installer targets Linux hosts."
[ -n "$DOMAIN" ] || { read -r -p "Panel domain (e.g. panel.example.com): " DOMAIN; }
[ -n "$DOMAIN" ] || die "a panel domain is required."
case "$DOMAIN" in
  localhost|127.0.0.1|*/*|*:*) die "'$DOMAIN' is not a valid public panel domain." ;;
esac
if [ "$CHANNEL" != "stable" ] && [ "$CHANNEL" != "dev" ]; then
  die "--channel must be 'stable' or 'dev'."
fi

command -v curl >/dev/null || die "curl is required."
command -v jq >/dev/null || die "jq is required (e.g. apt-get install -y jq)."
command -v docker >/dev/null || die "docker is required."
docker compose version >/dev/null 2>&1 || die "docker compose v2 plugin is required."

# ---------------------------------------------------------------- versions --
resolve_release() {
  local releases tag
  if [ -n "$VERSION" ]; then
    printf '%s' "$VERSION"
    return
  fi
  if [ "$CHANNEL" = "stable" ]; then
    curl -fsSL --retry 3 "$API_BASE/releases/latest" | jq -r '.tag_name'
    return
  fi
  releases=$(curl -fsSL --retry 3 "$API_BASE/releases?per_page=100") || die "cannot reach GitHub API."
  tag=$(printf '%s' "$releases" | jq -r '[.[] | select(.prerelease == true and (.tag_name | test("^[0-9]+\\.[0-9]+\\.[0-9]+-dev\\.[0-9]+\\.[0-9]+$")))][0].tag_name // empty')
  [ -n "$tag" ] || die "no dev release found; pass --version."
  printf '%s' "$tag"
}

[ "$DEFAULT_RELEASE_VERSION" = "latest" ] || VERSION="$DEFAULT_RELEASE_VERSION"
RELEASE_TAG=$(resolve_release)
[ -n "$RELEASE_TAG" ] || die "could not resolve a release (GitHub API rate limit? pass --version or retry later)."
log "Release: ${RELEASE_TAG} (channel: ${CHANNEL:-pinned})"

MANIFEST=$(curl -fsSL --retry 3 "${GH_BASE}/releases/download/${RELEASE_TAG}/release-manifest.json") \
  || die "release-manifest.json missing for ${RELEASE_TAG}."
XBOARD_VERSION=$(printf '%s' "$MANIFEST" | jq -r '.components.xboard.version // .version')
XBOARD_ADMIN_VERSION=$(printf '%s' "$MANIFEST" | jq -r '.components["xboard-admin"].version // empty')
XBOARD_UPDATER_VERSION=$(printf '%s' "$MANIFEST" | jq -r '.components["xboard-admin"].artifacts.updater_image // empty' | sed 's/.*://')
XBOARD_THEME_VERSION=$(printf '%s' "$MANIFEST" | jq -r '.components.dk_theme.version // empty')
[ -n "$XBOARD_ADMIN_VERSION" ] && [ -n "$XBOARD_THEME_VERSION" ] && [ -n "$XBOARD_UPDATER_VERSION" ] \
  || die "release manifest for ${RELEASE_TAG} has no complete component suite."
log "Suite: xboard ${XBOARD_VERSION} / admin ${XBOARD_ADMIN_VERSION} / theme ${XBOARD_THEME_VERSION} / updater ${XBOARD_UPDATER_VERSION}"

# ---------------------------------------------------------------- preflight --
PORT_OK=1
for port in 80 443; do
  if command -v ss >/dev/null 2>&1; then
    ss -ltn "sport = :${port}" | grep -q LISTEN && { warn "port ${port} is already in use; the entry cannot bind it."; PORT_OK=0; }
  else
    grep -q ":$(printf '%04X' "$port") " /proc/net/tcp /proc/net/tcp6 2>/dev/null && { warn "port ${port} is already in use; the entry cannot bind it."; PORT_OK=0; }
  fi
done
if [ "$ENTRY" -eq 1 ] && [ "$PORT_OK" -eq 0 ]; then
  die "free ports 80/443 first, or rerun with --no-entry."
fi

SERVER_IP=$(curl -fsS --retry 2 --max-time 10 https://api.ipify.org 2>/dev/null || true)
DOMAIN_IP=$(getent hosts "$DOMAIN" 2>/dev/null | awk '{print $1; exit}')
if [ -n "$SERVER_IP" ] && [ -n "$DOMAIN_IP" ] && [ "$SERVER_IP" != "$DOMAIN_IP" ]; then
  warn "$DOMAIN resolves to ${DOMAIN_IP}, this server reports ${SERVER_IP}."
  warn "ACME will fail until the DNS record points here."
fi

# ------------------------------------------------------------- deploy files --
if [ -e "${DEPLOY_DIR}/compose.yaml" ] || [ -s "${DEPLOY_DIR}/.env" ]; then
  [ "$FORCE" -eq 1 ] || die "${DEPLOY_DIR} already holds a deployment; use --force to overwrite files (data volumes are kept)."
  warn "overwriting files in ${DEPLOY_DIR}."
fi
mkdir -p "${DEPLOY_DIR}"
cd "${DEPLOY_DIR}"

for asset in compose.sample.yaml compose.entry.sample.yaml; do
  curl -fsSL --retry 3 -o "$asset" "${GH_BASE}/releases/download/${RELEASE_TAG}/${asset}" \
    || die "asset ${asset} missing from release ${RELEASE_TAG}."
done
cp compose.sample.yaml compose.yaml
[ "$ENTRY" -eq 1 ] && cp compose.entry.sample.yaml compose.entry.yaml

[ -n "$ACME_EMAIL" ] || { read -r -p "ACME contact email for certificate notices: " ACME_EMAIL; }
[ -n "$ACME_EMAIL" ] || die "an ACME contact email is required."
case "$ACME_EMAIL" in
  *@*) : ;;
  *) die "'$ACME_EMAIL' is not a valid email address." ;;
esac

[ -n "$ADMIN_EMAIL" ] || { read -r -p "Administrator account email: " ADMIN_EMAIL; }
case "$ADMIN_EMAIL" in
  *@*) : ;;
  *) die "'$ADMIN_EMAIL' is not a valid email address." ;;
esac
[ -n "$ADMIN_PASSWORD" ] || ADMIN_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -d '=+/' | head -c 20)"
[ "${#ADMIN_PASSWORD}" -ge 8 ] || die "administrator password must be at least 8 characters."
[ -n "$ADMIN_PATH" ] || ADMIN_PATH="$(head -c 4 /dev/urandom | od -An -tx1 | tr -d ' \n')"
case "$ADMIN_PATH" in
  [A-Za-z0-9_-][A-Za-z0-9_-][A-Za-z0-9_-][A-Za-z0-9_-][A-Za-z0-9_-][A-Za-z0-9_-][A-Za-z0-9_-][A-Za-z0-9_-]*) : ;;
  *) die "admin path must be 8-32 letters, digits, '_' or '-'." ;;
esac

mkdir -p secrets entry
umask 027
head -c 32 /dev/urandom | base64 | tr -d '=+/' > secrets/admin_route_token
chmod 600 secrets/admin_route_token
chown root:1000 secrets/admin_route_token 2>/dev/null || chgrp 1000 secrets/admin_route_token 2>/dev/null || true
printf '%s' "$ADMIN_PASSWORD" > secrets/install_admin_password
chmod 600 secrets/install_admin_password

if [ "$ENTRY" -eq 1 ]; then
  printf '{\n\tadmin :2019\n}\n' > entry/Caddyfile
fi

umask 022
cat > .env <<ENV
XBOARD_DEPLOY_DIR=${DEPLOY_DIR}
XBOARD_VERSION=${XBOARD_VERSION}
XBOARD_THEME_VERSION=${XBOARD_THEME_VERSION}
XBOARD_ADMIN_VERSION=${XBOARD_ADMIN_VERSION}
XBOARD_UPDATER_VERSION=${XBOARD_UPDATER_VERSION}
XBOARD_PANEL_URL=https://${DOMAIN}
XBOARD_THEME_PORT=7002
XBOARD_PORT=7001
ENV
if [ "$ENTRY" -eq 1 ]; then
  cat >> .env <<ENV
XBOARD_ENTRY_ENABLED=1
XBOARD_PANEL_DOMAIN=${DOMAIN}
ENV
fi
chmod 600 .env

# ------------------------------------------------------------------- start --
COMPOSE_FILES=(-f compose.yaml)
[ "$ENTRY" -eq 1 ] && COMPOSE_FILES+=(-f compose.entry.yaml)

# The suite installs before the managed start: the backend container only
# passes its health check once the installer wrote APP_KEY and the runtime
# drivers into the mounted .env.
log "running the non-interactive panel install (first pull may take a few minutes)..."
docker compose "${COMPOSE_FILES[@]}" run --rm \
  --volume "${DEPLOY_DIR}/secrets/install_admin_password:/tmp/install_admin_password:ro" \
  -e ENABLE_SQLITE=1 -e ENABLE_REDIS=1 \
  -e ADMIN_ACCOUNT="$ADMIN_EMAIL" \
  -e ADMIN_PASSWORD_FILE=/tmp/install_admin_password \
  -e ADMIN_SECURE_PATH="$ADMIN_PATH" \
  -e APP_URL="https://${DOMAIN}" \
  xboard php artisan xboard:install \
  || die "panel install failed; see: docker compose ${COMPOSE_FILES[*]} logs xboard"
rm -f secrets/install_admin_password

log "starting the suite..."
docker compose "${COMPOSE_FILES[@]}" up -d --wait >/dev/null \
  || die "compose failed; inspect with: docker compose ${COMPOSE_FILES[*]} ps && docker compose ${COMPOSE_FILES[*]} logs"

# ---------------------------------------------------- admin API bootstrapping --
api() {
  local method="$1" path="$2" token="$3" body="${4:-}"
  if [ -n "$body" ]; then
    curl -fsS -X "$method" -H "Authorization: ${token}" -H 'Content-Type: application/json' \
      -d "$body" "http://127.0.0.1:7002/api/v2/${ADMIN_PATH}/${path}"
  else
    curl -fsS -X "$method" -H "Authorization: ${token}" \
      "http://127.0.0.1:7002/api/v2/${ADMIN_PATH}/${path}"
  fi
}

AUTH_DATA=$(curl -fsS -X POST -H 'Content-Type: application/json' \
  -d "{\"email\":\"${ADMIN_EMAIL}\",\"password\":\"${ADMIN_PASSWORD}\"}" \
  "http://127.0.0.1:7002/api/v1/passport/auth/login" | jq -r '.data.auth_data // empty')
[ -n "$AUTH_DATA" ] || die "admin login failed after install."

if [ "$TEST_USER" -eq 1 ]; then
  api POST 'user/generate' "$AUTH_DATA" \
    '{"email_prefix":"test","email_suffix":"test.user","password":"testuser"}' >/dev/null \
    && log "internal test user created." \
    || warn "test user creation failed."
fi

MCP_KEY=$(api POST 'mcp/keys/create' "$AUTH_DATA" '{"name":"installer"}' | jq -r '.data.key // .data.token // empty')
api POST 'server/certificate/save' "$AUTH_DATA" \
  "{\"scope\":\"panel\",\"name\":\"Panel entry\",\"source_type\":\"acme_http\",\"domains\":[\"${DOMAIN}\"],\"auto_renew\":true,\"email\":\"${ACME_EMAIL}\"}" >/dev/null \
  && log "panel certificate resource registered; the entry updater signs it automatically." \
  || warn "certificate resource creation failed; seed domains still cover the panel."

# ----------------------------------------------------------------- summary --
printf '\n'
log "deployment complete."
printf '  User panel : https://%s\n' "$DOMAIN"
printf '  Admin URL  : https://%s/%s/\n' "$DOMAIN" "$ADMIN_PATH"
printf '  Admin user : %s\n' "$ADMIN_EMAIL"
printf '  Password   : %s  (keep it now; it is not stored)\n' "$ADMIN_PASSWORD"
if [ -n "$MCP_KEY" ]; then
  printf '  MCP server : https://%s/api/mcp\n  MCP key    : %s\n' "$DOMAIN" "$MCP_KEY"
fi
printf '  Versions   : xboard %s / admin %s / theme %s / updater %s\n' \
  "$XBOARD_VERSION" "$XBOARD_ADMIN_VERSION" "$XBOARD_THEME_VERSION" "$XBOARD_UPDATER_VERSION"
printf '\n  Deploy dir : %s\n  Logs       : docker compose %s logs\n' \
  "$DEPLOY_DIR" "${COMPOSE_FILES[*]}"
[ "$ENTRY" -eq 1 ] || printf '  Entry      : external reverse proxy on theme port 7002\n'
printf '\nUpgrades and rollbacks run from the Admin panel (版本更新), not this script.\n'
