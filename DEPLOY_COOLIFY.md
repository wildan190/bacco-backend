# Panduan Deploy Bacco Backend di Coolify

Backend Laravel 11 ini telah dikonfigurasi penuh untuk di-deploy ke **Coolify** menggunakan Docker / Docker Compose.

---

## 1. Spesifikasi Container
* **Base Image**: PHP 8.3 FPM Alpine (sangat ringan & aman)
* **Web Server**: Nginx (port 80)
* **Process Manager**: Supervisord (menjalankan Nginx, PHP-FPM, dan Laravel Queue Worker secara otomatis)
* **Ekstensi PHP Terpasang**: `pdo_mysql`, `gd` (support webp, jpeg, freetype), `exif`, `bcmath`, `zip`, `opcache`, `pcntl`, `intl`
* **Queue Worker**: Menggunakan driver Redis bawaan (tanpa perlu Horizon) untuk background jobs (sinkronisasi foto ke S3 & cache Redis)
* **Health Check**: Endpoint bawaan `/up`

---

## 2. Cara Deploy di Coolify

### Opsi A: Deploy sebagai Application (Rekomendasi jika MySQL & Redis terpisah di Coolify)
1. Di Dashboard Coolify, klik **+ Add Resource** -> **Application** -> **Public/Private Git Repository**.
2. Masukkan URL repository git dan tentukan subdirektori (jika monorepo): `bacco-backend`.
3. Pilih **Build Pack**: **Dockerfile**.
4. **Port**: Isi dengan `80`.
5. **Domain**: Masukkan domain production:
   ```
   https://api-bacco.phoenixtech.web.id
   ```
6. Masukkan **Environment Variables** (lihat bagian 3 di bawah).
7. Klik **Deploy**.

---

### Opsi B: Deploy sebagai Docker Compose (Jika MySQL & Redis ingin dalam 1 stack)
1. Di Dashboard Coolify, klik **+ Add Resource** -> **Docker Compose**.
2. Arahkan ke file `docker-compose.yml`.
3. Masukkan Environment Variables dan tentukan domain pada service `app`.
4. Klik **Deploy**.

---

## 3. Environment Variables Wajib di Coolify

Salin variabel berikut ke menu **Environment Variables** di Coolify:

```env
APP_NAME="Bacco Sales API"
APP_ENV=production
APP_KEY=base64:OGth0tQGzl4SbKoBe4rHAu8UGossysxfmJmw/dsdQnk=
APP_DEBUG=false
APP_URL=https://api-bacco.phoenixtech.web.id

APP_LOCALE=id
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info

# Database (Sesuaikan dengan kredensial MySQL di Coolify)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=db_bacco_sales_track_app
DB_USERNAME=root
DB_PASSWORD=

# Session, Cache & Queue (Redis)
SESSION_DRIVER=database
SESSION_LIFETIME=120
CACHE_STORE=redis
QUEUE_CONNECTION=redis

# Redis (Sesuaikan dengan host service Redis di Coolify)
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Object Storage S3 (Neva Objects)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=IEGGKPLA14XPJHR2AZZV
AWS_SECRET_ACCESS_KEY=1gabnDE08W1VoeLfXuVEkzPx6J4aXbQp7HLJJcgY
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=huntr-dev
AWS_ENDPOINT=https://s3.nevaobjects.id
AWS_USE_PATH_STYLE_ENDPOINT=true

# Otomatis migrate database saat container startup
RUN_MIGRATIONS=true
```

---

## 4. Fitur Otomatis Saat Startup (`docker/entrypoint.sh`)
Setiap kali container dijalankan / redeploy:
1. Otomatis membuat folder penyimpanan dan permission `storage` & `bootstrap/cache` (`775` www-data).
2. Otomatis menjalankan `php artisan storage:link`.
3. Otomatis menjalankan migrasi database: `php artisan migrate --force` (jika `RUN_MIGRATIONS=true`).
4. Otomatis optimasi performa: `config:cache`, `route:cache`, dan `view:cache`.
5. Supervisord menjalankan web server Nginx + PHP-FPM + 2 worker `queue:work redis` secara terus-menerus.
