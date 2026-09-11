#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

CI_USER="xboard-ci"
CI_HOME="/home/xboard-ci"
CONTROL_DIR="/etc/xboard-ci"
LIBEXEC_DIR="/usr/local/libexec/xboard-ci"
PUBLIC_KEY_FILE="${1:-}"
SOURCE_DIR="${2:-}"

fail() {
    printf '[xboard-ci-install] ERROR: %s\n' "$*" >&2
    exit 1
}

require_source() {
    [ -f "$SOURCE_DIR/$1" ] || fail "missing installer source: $1"
}

[ "$(id -u)" = "0" ] || fail "installer must run as root"
[ -f "$PUBLIC_KEY_FILE" ] || fail "public key file is required"
[ -d "$SOURCE_DIR" ] || fail "source directory is required"
for file in xboard-ci-entrypoint.sh xboard-ci-dispatcher.sh deploy.sh deploy-theme.sh deploy-admin.sh verify-theme.sh verify-production.sh compose.yaml production-bootstrap.php xboard-mcp.conf apply-mcp-compat.sh; do
    require_source "$file"
done

PUBLIC_KEY=$(tr -d '\r\n' < "$PUBLIC_KEY_FILE")
[[ "$PUBLIC_KEY" =~ ^ssh-ed25519\ [A-Za-z0-9+/=]+\ xboard-github-actions-production$ ]] || fail "the CI public key is invalid"

if ! id "$CI_USER" >/dev/null 2>&1; then
    /usr/sbin/useradd --create-home --home-dir "$CI_HOME" --shell /bin/bash --user-group --comment "Restricted GitHub Actions deploy principal" "$CI_USER"
fi
/usr/bin/passwd -l "$CI_USER" >/dev/null
[ "$(id -nG "$CI_USER")" = "$CI_USER" ] || fail "CI user belongs to unexpected supplementary groups"

install -o root -g root -d -m 755 "$CI_HOME" "$CI_HOME/.ssh"
printf 'restrict,command="/usr/local/bin/xboard-ci-entrypoint" %s\n' "$PUBLIC_KEY" > "$CI_HOME/.ssh/authorized_keys"
chown root:root "$CI_HOME/.ssh/authorized_keys"
chmod 644 "$CI_HOME/.ssh/authorized_keys"

install -o root -g root -m 755 "$SOURCE_DIR/xboard-ci-entrypoint.sh" /usr/local/bin/xboard-ci-entrypoint
install -o root -g root -m 755 "$SOURCE_DIR/xboard-ci-dispatcher.sh" /usr/local/sbin/xboard-ci-dispatcher
install -o root -g root -d -m 755 "$LIBEXEC_DIR" "$LIBEXEC_DIR/assets"
install -o root -g root -m 755 "$SOURCE_DIR/deploy.sh" "$LIBEXEC_DIR/deploy-xboard.sh"
install -o root -g root -m 755 "$SOURCE_DIR/deploy-theme.sh" "$LIBEXEC_DIR/deploy-theme.sh"
install -o root -g root -m 755 "$SOURCE_DIR/deploy-admin.sh" "$LIBEXEC_DIR/deploy-admin.sh"
install -o root -g root -m 755 "$SOURCE_DIR/verify-theme.sh" "$LIBEXEC_DIR/verify-theme.sh"
install -o root -g root -m 755 "$SOURCE_DIR/verify-production.sh" "$LIBEXEC_DIR/verify-production.sh"
install -o root -g root -m 644 "$SOURCE_DIR/compose.yaml" "$LIBEXEC_DIR/assets/compose.yaml"
install -o root -g root -m 644 "$SOURCE_DIR/production-bootstrap.php" "$LIBEXEC_DIR/assets/production-bootstrap.php"
install -o root -g root -m 644 "$SOURCE_DIR/xboard-mcp.conf" "$LIBEXEC_DIR/assets/xboard-mcp.conf"
install -o root -g root -m 755 "$SOURCE_DIR/apply-mcp-compat.sh" "$LIBEXEC_DIR/assets/apply-mcp-compat.sh"

install -o root -g root -d -m 700 "$CONTROL_DIR" "$CONTROL_DIR/secrets" "$CONTROL_DIR/reset-authorizations"
for secret in admin_password test_user_password; do
    if [ ! -f "$CONTROL_DIR/secrets/$secret" ]; then
        [ -f "/home/beihai/docker/xboard/secrets/$secret" ] || fail "existing $secret is unavailable for protected migration"
        install -o root -g root -m 600 "/home/beihai/docker/xboard/secrets/$secret" "$CONTROL_DIR/secrets/$secret"
    fi
done
if [ ! -f "$CONTROL_DIR/secrets/server_token" ]; then
    openssl rand -hex 32 > "$CONTROL_DIR/secrets/server_token"
    chown root:root "$CONTROL_DIR/secrets/server_token"
    chmod 600 "$CONTROL_DIR/secrets/server_token"
fi
if [ ! -f "$CONTROL_DIR/secrets/admin_route_token" ]; then
    openssl rand -hex 32 > "$CONTROL_DIR/secrets/admin_route_token"
    chown root:root "$CONTROL_DIR/secrets/admin_route_token"
    chmod 600 "$CONTROL_DIR/secrets/admin_route_token"
fi

printf '%s ALL=(root) NOPASSWD: /usr/local/sbin/xboard-ci-dispatcher *\n' "$CI_USER" > /etc/sudoers.d/xboard-ci-deployer
chown root:root /etc/sudoers.d/xboard-ci-deployer
chmod 440 /etc/sudoers.d/xboard-ci-deployer
/usr/sbin/visudo -cf /etc/sudoers.d/xboard-ci-deployer >/dev/null

for path in /usr/local/bin/xboard-ci-entrypoint /usr/local/sbin/xboard-ci-dispatcher "$LIBEXEC_DIR/deploy-xboard.sh" "$LIBEXEC_DIR/deploy-theme.sh" "$LIBEXEC_DIR/deploy-admin.sh" "$LIBEXEC_DIR/verify-theme.sh" "$LIBEXEC_DIR/verify-production.sh"; do
    [ "$(stat -c '%U:%G' "$path")" = "root:root" ] || fail "trusted executable ownership is invalid"
done

/usr/local/sbin/xboard-ci-dispatcher health
ssh-keygen -lf "$PUBLIC_KEY_FILE" | awk '{print "key_fingerprint=" $2 " principal=xboard-ci permissions=forced-command-only"}'
