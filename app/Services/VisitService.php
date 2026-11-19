<?php

namespace App\Services;

use App\Jobs\SaveStoreVisitJob;
use App\Models\Store;
use App\Models\StoreVisit;
use App\Models\User;
use App\Support\PhotoStorage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VisitService
{
    /**
     * Get paginated visits with user scoping (sales sees only own visits),
     * cached on the server-side for lightning-fast gallery rendering.
     * Caches lightweight IDs & counts as primitives to prevent PHP object serialization issues.
     *
     * @param  array{store_id?: int, user_id?: int, date?: string, per_page?: int}  $filters
     */
    public function getVisits(User $currentUser, array $filters = []): LengthAwarePaginator
    {
        $page = (int) request()->input('page', 1);
        $perPage = (int) ($filters['per_page'] ?? 15);
        $version = (int) Cache::get('visits_cache_version', 1);
        $cacheKey = "visits_v{$version}_u{$currentUser->id}_r{$currentUser->role}_p{$page}_pp{$perPage}_" . md5(json_encode($filters));

        $cached = Cache::remember($cacheKey, 300, function () use ($currentUser, $filters, $page, $perPage) {
            $query = StoreVisit::query();

            // Data isolation: Sales users can only see their own visits
            if ($currentUser->isSales()) {
                $query->where('user_id', $currentUser->id);
            } elseif (! empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }

            if (! empty($filters['store_id'])) {
                $query->where('store_id', $filters['store_id']);
            }

            if (! empty($filters['date'])) {
                $query->whereDate('visited_at', $filters['date']);
            }

            if (! empty($filters['has_photo'])) {
                $query->whereNotNull('photo_path');
            }

            if (! empty($filters['search'])) {
                $search = trim($filters['search']);
                $query->where(function ($q) use ($search) {
                    $q->whereHas('store', function ($sq) use ($search) {
                        $sq->where('name', 'like', "%{$search}%")
                            ->orWhere('address', 'like', "%{$search}%");
                    })->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%");
                    });
                });
            }

            $paginated = $query->latest('visited_at')->paginate($perPage, ['id'], 'page', $page);

            return [
                'ids' => $paginated->pluck('id')->all(),
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
            ];
        });

        if (! is_array($cached) || ! isset($cached['ids'])) {
            $cached = ['ids' => [], 'total' => 0, 'per_page' => $perPage, 'current_page' => $page];
        }

        $ids = $cached['ids'];
        if (empty($ids)) {
            $visits = collect();
        } else {
            $models = StoreVisit::with(['user:id,name,phone', 'store:id,name,address'])
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');

            $visits = collect($ids)->map(fn ($id) => $models->get($id))->filter()->values();
        }

        return new ConcretePaginator(
            $visits,
            $cached['total'] ?? 0,
            $cached['per_page'] ?? $perPage,
            $cached['current_page'] ?? $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );
    }

    /**
     * Record a new store visit with optional photo upload.
     * Dispatches SaveStoreVisitJob to Redis queue for background execution.
     *
     * @param  array{store_id: int, photo?: UploadedFile|null, photo_base64?: string|null, notes?: string|null, latitude?: float|null, longitude?: float|null, visited_at?: string|null}  $data
     */
    public function recordVisit(User $salesUser, array $data): StoreVisit
    {
        if (! empty($data['client_uuid'])) {
            $existing = StoreVisit::where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                return $existing->load(['user:id,name,phone', 'store:id,name,address']);
            }
        }

        $photoPath = null;
        // Store on the same disk PhotoStorage builds URLs from, so the visit photo
        // is immediately reachable (no background copy to S3 required).
        $photoDisk = PhotoStorage::disk();
        if (! empty($data['photo']) && $data['photo'] instanceof UploadedFile) {
            $photoPath = $this->compressAndStorePhoto($data['photo'], $photoDisk);
        } elseif (! empty($data['photo_base64'])) {
            $photoPath = $this->saveBase64Photo($data['photo_base64'], $photoDisk);
        }

        $visitedAt = ! empty($data['visited_at']) ? $data['visited_at'] : now();
        $today = today()->toDateString();

        // Immediate flag in Redis cache for instant POS eligibility and store status
        Cache::put("user_visited_store_{$salesUser->id}_{$data['store_id']}_{$today}", true, 86400);
        Cache::put("store_visited_{$data['store_id']}_{$today}", true, 86400);

        $jobData = [
            'client_uuid' => $data['client_uuid'] ?? null,
            'user_id'     => $salesUser->id,
            'store_id'    => (int) $data['store_id'],
            'photo_path'  => $photoPath,
            'notes'       => $data['notes'] ?? null,
            'latitude'    => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude'   => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'visited_at'  => $visitedAt instanceof \DateTimeInterface ? $visitedAt->format('Y-m-d H:i:s') : (string) $visitedAt,
        ];

        // Dispatch to Redis queue job
        SaveStoreVisitJob::dispatch($jobData);

        // If queue executed synchronously (e.g. sync driver during testing)
        $visit = StoreVisit::where('user_id', $salesUser->id)
            ->where('store_id', $data['store_id'])
            ->latest('id')
            ->first();

        if (! $visit) {
            $visit = new StoreVisit($jobData);
            $visit->id = 0;
            $visit->setRelation('user', $salesUser);
            $visit->setRelation('store', Store::find($data['store_id']));
        } else {
            $visit->load(['user:id,name,phone', 'store:id,name,address']);
        }

        return $visit;
    }

    /**
     * Check if a store has been visited today.
     *
     * @return array{is_visited_today: bool, latest_visit: StoreVisit|null, label: string|null}
     */
    public function getStoreVisitStatus(Store $store): array
    {
        $latestVisit = StoreVisit::with(['user:id,name'])
            ->where('store_id', $store->id)
            ->latest('visited_at')
            ->first();

        $today = today()->toDateString();
        $isVisitedInCache = Cache::has("store_visited_{$store->id}_{$today}");

        if (! $latestVisit) {
            if ($isVisitedInCache) {
                return [
                    'is_visited_today' => true,
                    'latest_visit' => null,
                    'label' => 'Toko telah dikunjungi hari ini',
                ];
            }

            return [
                'is_visited_today' => false,
                'latest_visit' => null,
                'label' => null,
            ];
        }

        $isToday = $latestVisit->visited_at->isToday() || $isVisitedInCache;
        $salesName = $latestVisit->user?->name ?? 'Sales';
        $timeStr = $latestVisit->visited_at->translatedFormat('d M Y, H:i');

        return [
            'is_visited_today' => $isToday,
            'latest_visit' => $latestVisit,
            'label' => "Toko telah dikunjungi oleh {$salesName} pada {$timeStr}",
        ];
    }

    /**
     * Store and compress an image file to reduce storage usage and speed up network transfer.
     * Scales image down to max 1200x1200 maintaining aspect ratio and compresses with quality 70.
     */
    protected function compressAndStorePhoto(UploadedFile|string $file, ?string $disk = null): ?string
    {
        $disk ??= PhotoStorage::disk();
        $fileName = 'visits/'.Str::uuid().'.jpg';

        $binary = $file instanceof UploadedFile ? file_get_contents($file->getRealPath()) : $file;
        if (empty($binary)) {
            return null;
        }

        // Only the compression is best-effort; storing must never fail silently.
        $payload = null;

        try {
            $source = @imagecreatefromstring($binary);
            if ($source) {
                // Correct EXIF orientation if available (from phone cameras)
                if ($file instanceof UploadedFile && function_exists('exif_read_data')) {
                    try {
                        $exif = @exif_read_data($file->getRealPath());
                        if (! empty($exif['Orientation'])) {
                            switch ($exif['Orientation']) {
                                case 3:
                                    $source = imagerotate($source, 180, 0);
                                    break;
                                case 6:
                                    $source = imagerotate($source, -90, 0);
                                    break;
                                case 8:
                                    $source = imagerotate($source, 90, 0);
                                    break;
                            }
                        }
                    } catch (\Throwable) {
                        // Ignore EXIF errors
                    }
                }

                $origWidth = imagesx($source);
                $origHeight = imagesy($source);

                $maxDim = 1200;
                if ($origWidth > $maxDim || $origHeight > $maxDim) {
                    if ($origWidth >= $origHeight) {
                        $newWidth = $maxDim;
                        $newHeight = (int) round(($origHeight / $origWidth) * $maxDim);
                    } else {
                        $newHeight = $maxDim;
                        $newWidth = (int) round(($origWidth / $origHeight) * $maxDim);
                    }

                    $resized = imagecreatetruecolor($newWidth, $newHeight);
                    imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
                    imagedestroy($source);
                    $source = $resized;
                }

                // Compress as JPEG quality 70
                ob_start();
                imagejpeg($source, null, 70);
                $payload = ob_get_clean();
                imagedestroy($source);
            }
        } catch (\Throwable $e) {
            Log::warning('Image compression error in VisitService: '.$e->getMessage());
        }

        // Could not decode/compress (e.g. unsupported format): store the original bytes as-is.
        if ($payload === null) {
            if ($file instanceof UploadedFile) {
                return $file->store('visits', $disk);
            }

            $payload = $binary;
        }

        return PhotoStorage::put($fileName, $payload, $disk);
    }

    /**
     * Save and compress base64 image data onto the given disk.
     */
    protected function saveBase64Photo(string $base64String, ?string $disk = null): ?string
    {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
            $data = substr($base64String, strpos($base64String, ',') + 1);
            $data = base64_decode($data);
            if ($data === false) {
                return null;
            }
        } else {
            $data = base64_decode($base64String);
            if ($data === false) {
                return null;
            }
        }

        return $this->compressAndStorePhoto($data, $disk);
    }
}
