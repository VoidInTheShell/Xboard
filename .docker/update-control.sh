#!/bin/sh
set -eu
cd /www
action="${1:?action required}"
task="${2:?task id required}"
case "$task" in *[!a-fA-F0-9-]*|'') exit 2;; esac
gate=/www/.docker/.data/update-maintenance
control() { supervisorctl -c /etc/supervisor/conf.d/supervisord.conf "$@"; }
wait_control() {
    attempts=0
    until control pid >/dev/null 2>&1; do
        attempts=$((attempts+1))
        [ "$attempts" -lt 180 ] || return 1
        sleep 1
    done
}
stop_group() {
    control pid >/dev/null
    state=$(control status "$1:*" 2>/dev/null || true)
    test -n "$state"
    case "$state" in *RUNNING*|*STARTING*|*BACKOFF*) control stop "$1:*";; esac
    state=$(control status "$1:*" 2>/dev/null || true)
    case "$state" in *RUNNING*|*STARTING*|*BACKOFF*|*STOPPING*) return 1;; *STOPPED*|*EXITED*|*FATAL*) :;; *) return 1;; esac
}
start_group() {
    state=$(control status "$1:*" 2>/dev/null || true)
    case "$state" in *RUNNING*) return 0;; *) control start "$1:*";; esac
}
case "$action" in
 quiesce)
    # This directory must be shared across old/new containers.
    test -d /www/.docker/.data
    touch "$gate"
    wait_control
    stop_group caddy
    stop_group octane
    stop_group ws-server
    stop_group horizon
    ;;
 backup|restore|verify)
    test -f "$gate"
    php artisan update:database "$action" "$task"
    ;;
 migrate)
    test -f "$gate"
    php artisan migrate --force --no-interaction
    php artisan optimize:clear
    ;;
 resume)
    test -f "$gate"
    wait_control
    [ "${ENABLE_WEB:-true}" != true ] || start_group octane
    [ "${ENABLE_WS_SERVER:-true}" != true ] || start_group ws-server
    [ "${ENABLE_HORIZON:-true}" != true ] || start_group horizon
    [ "${ENABLE_CADDY:-true}" != true ] || start_group caddy
    rm "$gate"
    ;;
 *) exit 2;;
esac
