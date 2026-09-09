#!/bin/bash
set -e
php artisan migrate --force --no-interaction
chown -R www-data:www-data storage bootstrap/cache
runuser -u www-data -- php artisan schedule:work --no-interaction &
scheduler_pid=$!
apache2-foreground &
server_pid=$!
trap 'kill "$scheduler_pid" "$server_pid" 2>/dev/null || true; wait || true' EXIT TERM INT
wait -n "$scheduler_pid" "$server_pid"
exit 1
