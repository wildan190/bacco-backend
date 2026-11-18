<?php

namespace App\Jobs;

use App\Models\StoreVisit;
use App\Models\VisitSchedule;
use App\Support\PhotoStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SaveStoreVisitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param array{
     *     user_id: int,
     *     store_id: int,
     *     photo_path?: string|null,
     *     notes?: string|null,
     *     latitude?: float|null,
     *     longitude?: float|null,
     *     visited_at: string
     * } $data
     */
    public function __construct(
        public array $data
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): StoreVisit
    {
        // Legacy safety net: photos uploaded by older deployments lived on the local
        // "public" disk while URLs are served from another disk (e.g. s3). Move them
        // across. New uploads already target the URL disk, so this is a no-op for them.
        $photoDisk = PhotoStorage::disk();
        $path = $this->data['photo_path'] ?? null;
        if ($path && $photoDisk !== 'public' && Storage::disk('public')->exists($path)) {
            try {
                $stream = Storage::disk('public')->readStream($path);
                if ($stream) {
                    Storage::disk($photoDisk)->writeStream($path, $stream, ['visibility' => 'public']);
                }
                Storage::disk('public')->delete($path);
            } catch (\Throwable $e) {
                Log::warning('Photo sync in SaveStoreVisitJob: ' . $e->getMessage());
            }
        }

        $visit = StoreVisit::create([
            'user_id'    => $this->data['user_id'],
            'store_id'   => $this->data['store_id'],
            'visited_at' => $this->data['visited_at'],
            'photo_path' => $this->data['photo_path'] ?? null,
            'notes'      => $this->data['notes'] ?? null,
            'latitude'   => $this->data['latitude'] ?? null,
            'longitude'  => $this->data['longitude'] ?? null,
        ]);

        // Auto-complete any pending visit schedule for this user and store today
        VisitSchedule::where('user_id', $this->data['user_id'])
            ->where('store_id', $this->data['store_id'])
            ->whereDate('scheduled_date', now()->toDateString())
            ->where('status', 'pending')
            ->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

        // Keep instant visit indicators active in Redis cache
        $today = today()->toDateString();
        Cache::put("user_visited_store_{$this->data['user_id']}_{$this->data['store_id']}_{$today}", true, 86400);
        Cache::put("store_visited_{$this->data['store_id']}_{$today}", true, 86400);

        // Invalidate cached visit queries so gallery is fresh immediately
        Cache::increment('visits_cache_version');

        return $visit;
    }
}
