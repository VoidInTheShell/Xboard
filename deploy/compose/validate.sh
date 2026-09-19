#!/bin/sh
set -eu

compose_file=${1:-compose.yaml}

: "${XBOARD_VERSION:?XBOARD_VERSION is required}"
: "${XBOARD_ADMIN_VERSION:?XBOARD_ADMIN_VERSION is required}"
: "${XBOARD_UPDATER_VERSION:?XBOARD_UPDATER_VERSION is required}"
: "${XBOARD_DEPLOY_DIR:?XBOARD_DEPLOY_DIR is required}"

version_pattern='^v[0-9]+\.[0-9]+\.[0-9]+(-dev\.[0-9]+\.[0-9]+)?$'
for name in XBOARD_VERSION XBOARD_ADMIN_VERSION XBOARD_UPDATER_VERSION; do
    case "$name" in
        XBOARD_VERSION) value=$XBOARD_VERSION ;;
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

docker compose -f "$compose_file" config --quiet
echo "Compose contract is valid: backend=$XBOARD_VERSION admin/updater=$XBOARD_ADMIN_VERSION"
