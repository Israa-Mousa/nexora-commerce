#!/usr/bin/env bash
# Nexora start-up on Render. Non-destructive: it never drops, truncates,
# resets or seeds the database. Migrations run only if RUN_MIGRATIONS=true
# (and then only "migrate --force", which adds missing tables/columns).
set -euo pipefail
cd /var/www/html

: "${PORT:=10000}"

if [[ -z "${APP_KEY:-}" ]]; then
    echo "[nexora] APP_KEY is not set — add it in Render > Environment." >&2
    exit 1
fi

# Render's generated value is plain base64 of 32 random bytes; Laravel expects "base64:<value>".
if [[ "$APP_KEY" != base64:* ]]; then
    export APP_KEY="base64:${APP_KEY}"
fi

# Managed MySQL (e.g. Aiven) needs TLS: the CA certificate is passed as text
# in DB_SSL_CA_PEM and written to a file for PDO.
if [[ -n "${DB_SSL_CA_PEM:-}" ]]; then
    printf '%s\n' "$DB_SSL_CA_PEM" > /etc/ssl/certs/nexora-db-ca.pem
    export MYSQL_ATTR_SSL_CA=/etc/ssl/certs/nexora-db-ca.pem
fi

envsubst '${PORT}' < /etc/nginx/nexora.conf.template > /etc/nginx/nginx.conf

# Public disk: product/category images ship inside the image under storage/app/public.
php artisan storage:link --force >/dev/null 2>&1 || true

php artisan config:clear >/dev/null
php artisan view:clear >/dev/null

if [[ "${RUN_MIGRATIONS:-false}" == "true" ]]; then
    php artisan migrate --force --no-interaction
fi

php artisan config:cache
php artisan view:cache || true
php artisan event:cache || true

chown -R www-data:www-data storage bootstrap/cache

echo "[nexora] starting nginx + php-fpm on port ${PORT}"
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/nexora.conf
