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
exec docker --host unix:///var/run/docker.sock exec "$container" sh /www/.docker/update-control.sh "$action" "$task"
