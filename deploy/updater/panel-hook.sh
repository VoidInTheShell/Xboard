#!/bin/sh
set -eu
# Install this file beside a host-owned file named backend-container containing
# the exact backend container name. No command or path comes from a task manifest.
base=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
container=$(cat "$base/backend-container")
case "$container" in *[!a-zA-Z0-9_.-]*|'') exit 2;; esac
action="${1:?action required}"
task_dir="${2:?task directory required}"
task=$(basename -- "$task_dir")
case "$action" in
 migrate|restore|verify|resume)
    # Compose returns before the new entrypoint has started Supervisor/Redis.
    # Keep application writers gated until the dependencies needed by Artisan
    # are ready. Do not print connection errors or credentials to task logs.
    attempts=0
    until docker --host unix:///var/run/docker.sock exec "$container" supervisorctl -c /etc/supervisor/conf.d/supervisord.conf pid >/dev/null 2>&1 \
      && docker --host unix:///var/run/docker.sock exec "$container" php -r 'require "/www/vendor/autoload.php"; $app=require "/www/bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $app->make("redis")->connection()->ping();' >/dev/null 2>&1; do
        attempts=$((attempts+1))
        [ "$attempts" -lt 180 ] || { echo "Backend update dependencies did not become ready" >&2; exit 1; }
        sleep 1
    done
    printf 'Backend dependencies ready after %s checks\n' "$attempts"
    ;;
esac
exec docker --host unix:///var/run/docker.sock exec "$container" sh /www/.docker/update-control.sh "$action" "$task"
