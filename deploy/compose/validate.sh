#!/bin/sh
set -eu

compose_file=${1:-compose.yaml}

# Read the deployment .env (both the working directory and the deployment
# directory) for the XBOARD_* variables below. Already-exported variables win,
# so CI environments that export the values directly are unaffected. Only the
# known keys are picked up; the file is never sourced.
env_read_value() {
    # $1: file, $2: key
    [ -f "$1" ] || return 1
    while IFS='=' read -r key value; do
        key=$(printf '%s' "$key" | tr -d ' \r')
        [ "$key" = "$2" ] || continue
        value=$(printf '%s' "$value" | tr -d '\r')
        printf '%s' "$value"
        return 0
    done < "$1"
    return 1
}

env_pick() {
    # env_pick VAR [default]
    eval "current=\${$1:-}"
    if [ -n "${current:-}" ]; then
        return 0
    fi
    if value=$(env_read_value ./.env "$1") && [ -n "$value" ]; then
        eval "$1=\"\$value\""
        return 0
    fi
    if value=$(env_read_value "${XBOARD_DEPLOY_DIR:-/nonexistent}/.env" "$1") && [ -n "$value" ]; then
        eval "$1=\"\$value\""
        return 0
    fi
    if [ $# -ge 2 ]; then
        eval "$1=\"\$2\""
    fi
}

: "${XBOARD_DEPLOY_DIR:=$(pwd)}"
env_pick XBOARD_VERSION
env_pick XBOARD_THEME_VERSION
env_pick XBOARD_ADMIN_VERSION
env_pick XBOARD_UPDATER_VERSION
env_pick XBOARD_PANEL_URL
env_pick XBOARD_ENTRY_ENABLED ""
env_pick XBOARD_PANEL_DOMAIN ""

: "${XBOARD_VERSION:?XBOARD_VERSION is required (set it in .env or the environment)}"
: "${XBOARD_THEME_VERSION:?XBOARD_THEME_VERSION is required (set it in .env or the environment)}"
: "${XBOARD_ADMIN_VERSION:?XBOARD_ADMIN_VERSION is required (set it in .env or the environment)}"
: "${XBOARD_UPDATER_VERSION:?XBOARD_UPDATER_VERSION is required (set it in .env or the environment)}"
: "${XBOARD_DEPLOY_DIR:?XBOARD_DEPLOY_DIR is required}"

version_pattern='^v[0-9]+\.[0-9]+\.[0-9]+(-dev\.[0-9]+\.[0-9]+)?$'
for name in XBOARD_VERSION XBOARD_THEME_VERSION XBOARD_ADMIN_VERSION XBOARD_UPDATER_VERSION; do
    case "$name" in
        XBOARD_VERSION) value=$XBOARD_VERSION ;;
        XBOARD_THEME_VERSION) value=$XBOARD_THEME_VERSION ;;
        XBOARD_ADMIN_VERSION) value=$XBOARD_ADMIN_VERSION ;;
        XBOARD_UPDATER_VERSION) value=$XBOARD_UPDATER_VERSION ;;
        *) echo "unsupported version variable: $name" >&2; exit 1 ;;
    esac
    printf '%s\n' "$value" | grep -Eq "$version_pattern" || {
        echo "$name must be an exact vX.Y.Z or vX.Y.Z-dev.RUN.ATTEMPT tag" >&2
        exit 1
    }
done

if [ "$XBOARD_ADMIN_VERSION" != "$XBOARD_UPDATER_VERSION" ]; then
    echo "XBOARD_ADMIN_VERSION and XBOARD_UPDATER_VERSION must be identical" >&2
    exit 1
fi

case "$XBOARD_DEPLOY_DIR" in
    /*) ;;
    *) echo "XBOARD_DEPLOY_DIR must be an absolute path" >&2; exit 1 ;;
esac

# The backend and the theme share the administrator route token. The file
# must exist before `docker compose up`; Docker would otherwise create a
# directory at the mount point and the panel integration would fail.
token_file="${XBOARD_DEPLOY_DIR}/secrets/admin_route_token"
if [ ! -f "$token_file" ]; then
    echo "administrator route token missing: ${token_file} must be a regular file" >&2
    echo "create it with the commands from the compose.sample.yaml header" >&2
    exit 1
fi

valid_domain() {
    case "$1" in
        ''|.*|*.|*..*|*[!a-z0-9.-]*) return 1 ;;
    esac
    printf '%s' "$1" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'
}

if [ "$XBOARD_ENTRY_ENABLED" = "1" ]; then
    caddy_file="${XBOARD_DEPLOY_DIR}/entry/Caddyfile"
    if [ ! -f "$caddy_file" ]; then
        echo "XBOARD_ENTRY_ENABLED=1 requires a Caddyfile at ${caddy_file}" >&2
        echo "create it with: install -d -m 0755 ${XBOARD_DEPLOY_DIR}/entry && printf '{\\n\\tadmin :2019\\n}\\n' > ${caddy_file}" >&2
        exit 1
    fi
    if [ -n "$XBOARD_PANEL_DOMAIN" ]; then
        old_ifs=$IFS
        IFS=,
        for domain in $XBOARD_PANEL_DOMAIN; do
            if ! valid_domain "$domain"; then
                echo "XBOARD_PANEL_DOMAIN contains an invalid domain: $domain" >&2
                exit 1
            fi
        done
        IFS=$old_ifs
    fi
fi

compose_env_args=""
if [ -f "${XBOARD_DEPLOY_DIR}/.env" ]; then
    compose_env_args="--env-file ${XBOARD_DEPLOY_DIR}/.env"
fi
if [ -n "$compose_env_args" ]; then
    docker compose $compose_env_args -f "$compose_file" config --quiet
else
    docker compose -f "$compose_file" config --quiet
fi
echo "Compose contract is valid: backend=$XBOARD_VERSION theme=$XBOARD_THEME_VERSION admin/updater=$XBOARD_ADMIN_VERSION"
