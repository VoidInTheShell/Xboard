#!/bin/sh
set -eu

exec sudo -n /usr/local/sbin/xboard-ci-dispatcher "${SSH_ORIGINAL_COMMAND:-}"
