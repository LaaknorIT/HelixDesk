#!/bin/sh
# HelixDesk container entrypoint.
#
#   web          FrankenPHP web server on port 8080 (default). Runs migrations first
#                unless HELIXDESK_MIGRATE=false.
#   horizon      Laravel Horizon queue workers.
#   scheduler    Laravel scheduler (mail polling, SLA checks, integration syncs).
#   healthcheck  Exit 0 when the role in this container is healthy.
#   anything else is executed as given, e.g. `php artisan helixdesk:create-admin`.
set -e

role="${1:-web}"
role_file=/tmp/helixdesk-role

require_app_key() {
    if [ -z "${APP_KEY}" ]; then
        echo "APP_KEY is not set. Generate one with: openssl rand -base64 32 (prefix it with base64:)" >&2
        exit 1
    fi
}

wait_for_database() {
    attempts=0
    until php artisan db:show >/dev/null 2>&1; do
        attempts=$((attempts + 1))
        if [ "${attempts}" -ge "${HELIXDESK_DB_WAIT_SECONDS:-60}" ]; then
            echo "Database is not reachable after ${attempts} seconds." >&2
            php artisan db:show
            exit 1
        fi
        sleep 1
    done
}

optimize() {
    php artisan optimize --no-ansi >/dev/null
    php artisan filament:optimize --no-ansi >/dev/null
}

case "${role}" in
    web | horizon | scheduler)
        echo "${role}" >"${role_file}"
        ;;
esac

case "${role}" in
    web)
        require_app_key
        wait_for_database
        if [ "${HELIXDESK_MIGRATE:-true}" = "true" ]; then
            php artisan migrate --force --isolated --no-ansi
        fi
        optimize
        exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
        ;;
    horizon)
        require_app_key
        wait_for_database
        optimize
        exec php artisan horizon
        ;;
    scheduler)
        require_app_key
        wait_for_database
        optimize
        exec php artisan schedule:work
        ;;
    healthcheck)
        case "$(cat "${role_file}" 2>/dev/null)" in
            horizon) exec php artisan horizon:status ;;
            scheduler) exit 0 ;;
            *) exec curl --fail --silent --show-error --output /dev/null "http://127.0.0.1:8080/up" ;;
        esac
        ;;
    *)
        exec "$@"
        ;;
esac
