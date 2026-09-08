#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

CONTROL_DIR="/etc/xboard-ci"
LIBEXEC_DIR="/usr/local/libexec/xboard-ci"
ORIGINAL_COMMAND="${1:-}"

fail() {
    printf '[xboard-ci] ERROR: %s\n' "$*" >&2
    exit 1
}

[ "$(id -u)" = "0" ] || fail "dispatcher must run as root"
[ -n "$ORIGINAL_COMMAND" ] || fail "a deployment command is required"
[ "${#ORIGINAL_COMMAND}" -le 512 ] || fail "deployment command is too long"
case "$ORIGINAL_COMMAND" in
    *$'\n'*|*$'\r'*) fail "control characters are not allowed" ;;
esac

read -r -a ARGS <<< "$ORIGINAL_COMMAND"
[ "${#ARGS[@]}" -gt 0 ] || fail "a deployment command is required"

validate_sha() {
    [[ "$1" =~ ^[0-9a-f]{40}$ ]] || fail "invalid release SHA"
}

validate_actor() {
    [[ "$1" =~ ^[A-Za-z0-9-]{1,39}$ ]] || fail "invalid registry user"
}

run_reset() {
    local sha="$1"
    shift
    local authorization="$CONTROL_DIR/reset-authorizations/$sha"
    local consumed="${authorization}.consumed.$$"

    [ -f "$authorization" ] || fail "fresh-data deployment is not armed on the server"
    [ "$(stat -c '%U:%G:%a' "$authorization")" = "root:root:600" ] || fail "reset authorization permissions are invalid"
    grep -Fxq 'RESET JPGREEN XBOARD DATA' "$authorization" || fail "reset authorization content is invalid"
    mv "$authorization" "$consumed"
    if "$@"; then
        rm -f -- "$consumed"
        return 0
    fi
    mv "$consumed" "$authorization"
    return 1
}

case "${ARGS[0]}" in
    health)
        [ "${#ARGS[@]}" -eq 1 ] || fail "health accepts no arguments"
        printf 'principal=xboard-ci forced_command=ready\n'
        ;;
    deploy-xboard)
        [ "${#ARGS[@]}" -eq 7 ] || fail "deploy-xboard arguments are invalid"
        validate_sha "${ARGS[1]}"
        [[ "${ARGS[2]}" =~ ^ghcr\.io/voidintheshell/xboard@sha256:[0-9a-f]{64}$ ]] || fail "invalid Xboard image"
        [[ "${ARGS[3]}" =~ ^ghcr\.io/voidintheshell/dk_theme@sha256:[0-9a-f]{64}$ ]] || fail "invalid theme image"
        validate_actor "${ARGS[4]}"
        case "${ARGS[5]}:${ARGS[6]}" in
            preserve:NO_RESET)
                logger -t xboard-ci "operation=deploy-xboard sha=${ARGS[1]} mode=preserve"
                "$LIBEXEC_DIR/deploy-xboard.sh" "${ARGS[1]}" "${ARGS[2]}" "${ARGS[3]}" "${ARGS[4]}" preserve
                ;;
            reset:RESET_JPGREEN_XBOARD_DATA)
                logger -t xboard-ci "operation=deploy-xboard sha=${ARGS[1]} mode=reset"
                run_reset "${ARGS[1]}" "$LIBEXEC_DIR/deploy-xboard.sh" "${ARGS[1]}" "${ARGS[2]}" "${ARGS[3]}" "${ARGS[4]}" reset
                ;;
            *) fail "reset mode or confirmation is invalid" ;;
        esac
        ;;
    deploy-theme)
        [ "${#ARGS[@]}" -eq 4 ] || fail "deploy-theme arguments are invalid"
        validate_sha "${ARGS[1]}"
        [[ "${ARGS[2]}" =~ ^ghcr\.io/voidintheshell/dk_theme@sha256:[0-9a-f]{64}$ ]] || fail "invalid theme image"
        validate_actor "${ARGS[3]}"
        logger -t xboard-ci "operation=deploy-theme sha=${ARGS[1]}"
        "$LIBEXEC_DIR/deploy-theme.sh" "${ARGS[1]}" "${ARGS[2]}" "${ARGS[3]}"
        ;;
    verify-production)
        [ "${#ARGS[@]}" -eq 1 ] || fail "verify-production accepts no arguments"
        "$LIBEXEC_DIR/verify-production.sh"
        ;;
    verify-theme)
        [ "${#ARGS[@]}" -eq 1 ] || fail "verify-theme accepts no arguments"
        "$LIBEXEC_DIR/verify-theme.sh"
        ;;
    *)
        fail "command is not permitted"
        ;;
esac
