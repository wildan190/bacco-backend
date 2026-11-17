<?php

namespace App\Jobs;

use App\Models\StoreVisit;
use App\Models\VisitSchedule;
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
        // If S3 is configured, upload the local file to S3 in the background
        if (! empty($this->data['photo_path']) && config('filesystems.default') === 's3') {
            try {
                if (Storage::disk('public')->exists($this->data['photo_path'])) {
                    $stream = Storage::disk('public')->readStream($this->data['photo_path']);
                    if ($stream) {
                        Storage::disk('s3')->writeStream($this->data['photo_path'], $stream, ['visibility' => 'public']);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('S3 background sync in SaveStoreVisitJob: ' . $e->getMessage());
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
