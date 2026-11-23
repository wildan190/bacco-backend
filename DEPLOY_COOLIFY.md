# Panduan Deploy Bacco Backend di Coolify & GitHub Actions

Backend Laravel 11 ini telah dikonfigurasi penuh untuk di-deploy ke **Coolify** menggunakan Docker / Docker Compose dengan batasan resource ketat (**RAM < 700MB, CPU < 1 Core, Redis hemat memory, dan Queue 1 worker**).

---

## 1. Alokasi Resource & Optimasi Performa

Stack ini dikunci agar **RAM tidak pernah tembus 700MB** dan **CPU tidak melebihi 1 Core**:

| Komponen | Batasan RAM | Batasan CPU | Optimasi Khusus |
|---|---|---|---|
| **PHP-FPM** | ~40MB - 120MB | Max 0.7 Core (shared) | Pool mode `ondemand` (max 4 children), timeout idle 10s otomatis dibunuh jika tidak ada request. Memory limit script 128MB. |
| **OPcache** | 64MB | - | Memory consumption dipangkas dari 128MB ke 64MB (cukup untuk seluruh route & class Laravel 11). CLI OPcache dimatikan. |
| **Nginx** | ~10MB | < 0.05 Core | Event epoll, worker processes auto, fastcgi buffer efisien. |
| **Laravel Queue** | Max 64MB | < 0.15 Core | **1 Worker saja** (`numprocs=1`), opsi `--sleep=3 --rest=1 --memory=64 --max-jobs=100` agar CPU tidak spike dan memory leak ter-recycle berkala. |
| **Redis** | Max 48MB | Max 0.15 Core | `--maxmemory 48mb`, policy `allkeys-lru` (auto hapus cache usang saat penuh), snapshot disk RDB dimatikan (`--save ""`) untuk mencegah forking memory 2x lipat. |
| **Container Hard Limits** | **450MB (App) + 64MB (Redis)** | **Max 0.70 Core (App)** | Dibatasi via Docker Compose `deploy.resources.limits`. |

---

## 2. Deploy via GitHub Actions (Coolify Tidak Auto-Deploy Sendiri)

Agar Coolify **tidak ikut deploy otomatis** setiap ada push biasa, melainkan dikendalikan melalui **GitHub Actions**:

### A. Nonaktifkan Auto-Deploy di Dashboard Coolify
1. Buka dashboard Coolify -> Pilih project **Bacco Backend**.
2. Masuk ke tab **Configuration** / **General**.
3. **Matikan (Uncheck)** opsi **"Auto Deploy"** (atau "Autodeploy via Webhook").
4. Simpan perubahan. Sekarang Coolify hanya akan melakukan deployment jika menerima trigger API / Webhook manual.

### B. Konfigurasi Secret di GitHub Repository
1. Buka repository GitHub `bacco-backend` -> **Settings** -> **Secrets and variables** -> **Actions**.
2. Klik **New repository secret**.
3. Masukkan:
   - **Name**: `COOLIFY_DEPLOY_SECRET`
   - **Value**: Masukkan API Token / Secret Coolify Anda.

### C. Alur Kerja GitHub Actions (`.github/workflows/deploy.yml`)
Setiap kali ada push ke branch `master` (atau dipicu manual via `workflow_dispatch`), GitHub Actions akan memanggil webhook Coolify:
```
https://coolify.phoenixtech.web.id/api/v1/deploy?uuid=pq7c1alsxtbzru2iyhbl1kcv&force=false
```
Dengan header `Authorization: Bearer ${{ secrets.COOLIFY_DEPLOY_SECRET }}`.

---

## 3. Spesifikasi File Konfigurasi Baru

1. **`docker/php.ini`**:
   - `memory_limit = 128M`
   - `opcache.memory_consumption = 64`
   - `max_execution_time = 60`
2. **`docker/www.conf`**:
   - PHP-FPM pool `ondemand` (hanya aktif saat ada request, 0MB saat idle).
   - Max 4 children processes.
3. **`docker/supervisord.conf`**:
   - 1 Worker Queue (`numprocs=1`).
   - Memory limit 64MB dan istirahat 1 detik tiap job selesai (`--rest=1`).
4. **`docker-compose.yml`**:
   - Redis dikunci `--maxmemory 48mb --maxmemory-policy allkeys-lru --save ""`.
   - Resource limits: App CPU 0.70 / RAM 450M, Redis CPU 0.15 / RAM 64M.
5. **`.github/workflows/deploy.yml`**:
   - Otomatis trigger Coolify webhook hanya melalui GitHub Actions.
