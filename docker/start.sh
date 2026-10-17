#!/bin/sh
# Container start on Render: cache config for this environment, apply pending
# migrations (RUN_MIGRATIONS=true), then run the queue worker and scheduler in
# the background and Apache in the foreground. On the free plan the whole
# container sleeps when idle; queued deliveries stay in the database and resume
# on the next wake (see broadcasts:resume in routes/console.php).
set -e
cd /var/www/html

as_app() { runuser -u www-data -- "$@"; }

as_app php artisan config:cache
as_app php artisan route:cache
as_app php artisan view:cache
as_app php artisan event:cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    as_app php artisan migrate --force
    as_app php artisan app:ensure-campuses
fi

# Demo accounts, only while DEMO_ACCOUNTS_PASSWORD is set in the dashboard.
# A weak password is reported in the log; the site still starts.
if [ -n "${DEMO_ACCOUNTS_PASSWORD:-}" ]; then
    as_app php artisan app:create-demo-accounts --from-env || true
fi

# Restarted if they exit (memory limit, --max-time, a crash).
( while true; do as_app php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --memory=128; sleep 5; done ) &
( while true; do as_app php artisan schedule:work; sleep 5; done ) &

exec apache2-foreground
