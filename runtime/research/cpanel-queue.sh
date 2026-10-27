#!/bin/sh
# Periodic CLI consumers for hosts without a persistent process supervisor.
set -eu

app_dir=${1:?Usage: cpanel-queue.sh APP_DIRECTORY [PHP_BINARY] [LANE]}
php_bin=${2:-php}
lane=${3:-1}
case "$lane" in 1|2|3) ;; *) echo 'Research queue lane must be 1, 2 or 3.' >&2; exit 2 ;; esac

for utility in flock timeout; do
    command -v "$utility" >/dev/null 2>&1 || { echo "Research queue requires $utility." >&2; exit 1; }
done
[ -f "$app_dir/artisan" ] && [ -d "$app_dir/storage/framework" ] || { echo 'Laravel application directory is invalid.' >&2; exit 1; }
cd "$app_dir"

# flock releases on process exit, including a timed-out/crashed PHP child.
# The outer deadline also works when the host's PHP has no PCNTL extension.
exec flock -n -E 0 "storage/framework/research-queue-$lane.lock" \
    timeout --signal=TERM --kill-after=10s 500s \
    "$php_bin" artisan queue:work database --queue=research --stop-when-empty \
    --max-time=45 --timeout=440 --tries=1 --sleep=1
