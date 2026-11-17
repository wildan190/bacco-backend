#!/bin/sh
set -e

echo "==> Preparing Laravel application..."

# Ensure storage directories exist and are writable
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create storage symlink if not present
if [ ! -L /var/www/html/public/storage ]; then
    echo "==> Creating storage symlink..."
    php /var/www/html/artisan storage:link || true
fi

# Run database migrations if enabled (default true in Coolify deploy)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "==> Running database migrations..."
    php /var/www/html/artisan migrate --force || echo "Warning: Migration failed or database not ready yet."
fi

# Optimization caches
echo "==> Caching configuration, routes, and views..."
php /var/www/html/artisan config:cache || true
php /var/www/html/artisan route:cache || true
php /var/www/html/artisan view:cache || true

echo "==> Starting Supervisord (Nginx + PHP-FPM + Laravel Queue Worker)..."
exec "$@"
