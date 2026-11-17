#!/bin/sh
set -e

echo "==> Preparing Laravel application..."

# 1. Pastikan direktori storage dan cache tersedia
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

# 2. Generate file .env dari environment variables sistem jika belum ada
# Ini memastikan PHP-FPM (yang memiliki clear_env=yes) tetap bisa membaca APP_KEY dan kredensial lain
if [ ! -f /var/www/html/.env ]; then
    echo "==> Generating .env from runtime environment variables..."
    printenv | grep -E '^(APP_|DB_|REDIS_|AWS_|FILESYSTEM_|CACHE_|SESSION_|QUEUE_|LOG_|MAIL_|SANCTUM_|CORS_)' > /var/www/html/.env || true
fi

# 3. Bersihkan cache konfigurasi lama agar tidak mengunci APP_KEY kosong
php /var/www/html/artisan config:clear || true
php /var/www/html/artisan route:clear || true
php /var/www/html/artisan view:clear || true

# 4. Buat storage symlink jika belum ada
if [ ! -L /var/www/html/public/storage ]; then
    echo "==> Creating storage symlink..."
    php /var/www/html/artisan storage:link || true
fi

# 5. Jalankan migrasi database jika diaktifkan
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "==> Running database migrations..."
    php /var/www/html/artisan migrate --force || echo "Warning: Migration failed or database not ready yet."
fi

# 6. Jalankan package discovery dan cache konfigurasi hanya jika APP_KEY terisi
php /var/www/html/artisan package:discover --ansi || true
if [ -n "$APP_KEY" ] || grep -q "^APP_KEY=base64:" /var/www/html/.env 2>/dev/null; then
    echo "==> Caching configuration..."
    php /var/www/html/artisan config:cache || true
else
    echo "WARNING: APP_KEY is empty! Skipping config:cache."
fi

# 7. Atur hak akses folder satu kali di akhir sebelum menjalankan Supervisord
touch /var/www/html/storage/logs/laravel.log
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
chmod 664 /var/www/html/storage/logs/laravel.log

echo "==> Starting Supervisord (Nginx + PHP-FPM + Laravel Queue Worker)..."
exec "$@"
