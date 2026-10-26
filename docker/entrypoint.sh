#!/bin/sh
set -eu

# Container start-up for the webinar platform.
#
# Everything here is idempotent: the container is immutable, so a restart must
# converge on the same state rather than accumulate changes.

log() { printf '[entrypoint] %s\n' "$1"; }

# ---------------------------------------------------------------------------
# HTTP port
# ---------------------------------------------------------------------------
# The platform assigns $PORT. nginx cannot read environment variables in its
# config, so substitute the placeholder written by the image build.
: "${PORT:=8080}"
sed -i "s/__PORT__/${PORT}/g" /etc/nginx/nginx.conf
log "nginx will listen on ${PORT}"

: "${PHP_FPM_MAX_CHILDREN:=2}"
case "$PHP_FPM_MAX_CHILDREN" in
    [1-9]|[1-9][0-9]) ;;
    *) log "FATAL: PHP_FPM_MAX_CHILDREN must be an integer from 1 to 99"; exit 1 ;;
esac
sed -i "s/__FPM_MAX_CHILDREN__/${PHP_FPM_MAX_CHILDREN}/g" /usr/local/etc/php-fpm.d/zz-webinar.conf

# Render's public edge redirects HTTP to HTTPS and terminates TLS before nginx.
# For this explicit Render-only profile, assert transport from the platform
# configuration, never from a caller-controlled X-Forwarded-Proto header.
# Keep the default listener-TLS behavior on every other hosting platform.
if [ "${RENDER_EDGE_TLS:-false}" = "true" ]; then
    if [ "${RENDER:-}" != "true" ] || [ -z "${RENDER_EXTERNAL_URL:-}" ]; then
        log "FATAL: RENDER_EDGE_TLS requires the Render platform"
        exit 1
    fi
    case "$RENDER_EXTERNAL_URL" in
        https://*) ;;
        *) log "FATAL: Render must provide an HTTPS public URL"; exit 1 ;;
    esac
    : "${APP_URL:=$RENDER_EXTERNAL_URL}"
    if [ "${APP_URL%/}" != "${RENDER_EXTERNAL_URL%/}" ]; then
        log "FATAL: This profile requires APP_URL to match the Render HTTPS URL"
        exit 1
    fi
    export APP_URL
    sed -i 's/fastcgi_param HTTPS $https if_not_empty;/fastcgi_param HTTPS on;/' /etc/nginx/nginx.conf
fi

# ---------------------------------------------------------------------------
# Writable state
# ---------------------------------------------------------------------------
mkdir -p storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/framework/nginx/client-body \
         storage/framework/nginx/fastcgi \
         storage/logs \
         storage/app/private \
         bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# ---------------------------------------------------------------------------
# Fail fast on missing configuration
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    log "FATAL: APP_KEY is not set. Generate one with 'php artisan key:generate --show'"
    log "and store it as a secret. Never let the container generate its own key:"
    log "a new key on restart makes every encrypted column unreadable."
    exit 1
fi

# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------
# RUN_MIGRATIONS is opt-in. With more than one instance, two containers starting
# together would both migrate; run migrations from a pre-deploy step or a
# one-off shell instead and leave this false.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    log "running database migrations"
    php artisan migrate --force --no-interaction
else
    log "skipping migrations (RUN_MIGRATIONS is not true)"
fi

# Optional first-deploy bootstrap. Render mounts this private JSON secret at
# runtime; it contains a password hash, never a plaintext password. The command
# only creates an account when the users table is empty, so restarts cannot
# reset credentials or reactivate a suspended administrator.
if [ -n "${ADMIN_BOOTSTRAP_JSON:-}" ]; then
    # Environment import supports private local files on Render Free. Import
    # only a hash payload, then remove it from the supervisor/worker environment.
    (umask 077; printf '%s' "$ADMIN_BOOTSTRAP_JSON" > /run/webinar-admin-bootstrap.json)
    unset ADMIN_BOOTSTRAP_JSON
    if ! php artisan admin:bootstrap --file=/run/webinar-admin-bootstrap.json --no-interaction; then
        rm -f /run/webinar-admin-bootstrap.json
        exit 1
    fi
    rm -f /run/webinar-admin-bootstrap.json
elif [ -n "${ADMIN_BOOTSTRAP_FILE:-}" ]; then
    php artisan admin:bootstrap --file="${ADMIN_BOOTSTRAP_FILE}" --no-interaction
fi

# ---------------------------------------------------------------------------
# Caches
# ---------------------------------------------------------------------------
# Built at start, not at image build: config:cache freezes environment values,
# and those are only known once the platform injects them.
log "building config, route, and view caches"
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

# Deliberately NOT running storage:link. Certificate PDFs are private and are
# served through an authenticated controller; a public symlink would expose
# them and the production preflight asserts no such route exists.

# ---------------------------------------------------------------------------
# Background work
# ---------------------------------------------------------------------------
mkdir -p /etc/supervisor/conf.d
rm -f /etc/supervisor/conf.d/workers.conf

if [ "${RUN_WORKERS:-true}" = "true" ]; then
    QUEUE_CONN="${QUEUE_CONNECTION:-redis}"
    EMAIL_CONN="${EMAIL_QUEUE_CONNECTION:-redis-emails}"
    EMAIL_Q="${EMAIL_QUEUE:-emails}"
    ACCOUNT_EMAIL_Q="${ACCOUNT_EMAIL_QUEUE:-account-emails}"

    # Timeouts mirror DEPLOYMENT_CHECKLIST.md: certificate generation is slow
    # and must stay below the queue's visibility timeout; email delivery is
    # short and must stay inside the provider's idempotency window.
    cat > /etc/supervisor/conf.d/workers.conf <<WORKERS
[program:queue-default]
command=php /var/www/html/artisan queue:work ${QUEUE_CONN} --queue=default --sleep=1 --tries=3 --timeout=1800
user=www-data
autostart=true
autorestart=true
stopwaitsecs=1810
priority=20
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:queue-emails]
command=php /var/www/html/artisan queue:work ${EMAIL_CONN} --queue=${ACCOUNT_EMAIL_Q},${EMAIL_Q} --sleep=1 --tries=3 --timeout=60
user=www-data
autostart=true
autorestart=true
stopwaitsecs=70
priority=20
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:scheduler]
command=php /var/www/html/artisan schedule:work
user=www-data
autostart=true
autorestart=true
priority=30
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
WORKERS
    log "queue workers and scheduler enabled (connections: ${QUEUE_CONN}, ${EMAIL_CONN})"
else
    log "workers disabled (RUN_WORKERS=false) - run them as separate services"
fi

log "starting supervisord"
exec supervisord -c /etc/supervisord.conf
