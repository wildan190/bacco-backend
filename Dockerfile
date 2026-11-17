# syntax=docker/dockerfile:1
FROM public.ecr.aws/docker/library/php:8.4-fpm-alpine

# Set working directory
WORKDIR /var/www/html

# 1. Install runtime dependencies (nginx, supervisor, mysql-client)
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    mysql-client

# 2. Install PHP extensions cepat (tanpa intl yang memakan waktu compile C++ 8-10 menit)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
    pdo_mysql \
    bcmath \
    zip \
    exif \
    gd \
    opcache \
    pcntl

# 3. Salin binary Composer langsung dari mirror AWS ECR (bebas rate-limit)
COPY --from=public.ecr.aws/docker/library/composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# 4. Copy file composer terlebih dahulu agar layer cache terjaga saat kode berubah
COPY composer.json composer.lock ./

# 5. Install dependensi dengan BuildKit cache agar tidak unduh ulang dari internet tiap deploy
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# 6. Copy source code aplikasi
COPY . .

# 7. Generate autoloader teroptimasi
RUN composer dump-autoload --optimize --no-dev --no-interaction --no-scripts --ignore-platform-reqs

# 8. Buat direktori storage & atur hak akses (hanya pada folder yang butuh tulis)
RUN mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# 9. Copy file konfigurasi server
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-custom.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://127.0.0.1/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
