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

# Package discovery and configuration cache (do NOT route:cache due to closure routes & Scramble)
echo "==> Clearing route/view cache and preparing config..."
php /var/www/html/artisan route:clear || true
php /var/www/html/artisan view:clear || true
php /var/www/html/artisan package:discover --ansi || true
php /var/www/html/artisan config:cache || true

# Final permission fix: ensure all files created by root above are fully accessible by www-data
touch /var/www/html/storage/logs/laravel.log
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
chmod 664 /var/www/html/storage/logs/laravel.log

echo "==> Starting Supervisord (Nginx + PHP-FPM + Laravel Queue Worker)..."
exec "$@"
