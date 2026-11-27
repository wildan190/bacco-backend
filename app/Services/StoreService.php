<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Models\VisitSchedule;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StoreService
{
    public function __construct(protected ImageService $imageService) {}

    /**
     * Get list of stores with eager loaded latestVisit and visitor user to prevent N+1 queries.
     * When called by a sales user, only returns stores assigned to them OR scheduled for today.
     *
     * @param  array{search?: string, is_active?: bool, per_page?: int, all?: bool}  $filters
     * @return LengthAwarePaginator|Collection<int, Store>
     */
    public function getStores(array $filters = [], ?User $currentUser = null): LengthAwarePaginator|Collection
    {
        $query = Store::query()
            ->with(['latestVisit.user:id,name,phone', 'assignedSales:id,name']);

        // ── Admin / Sales Multi-Tenant Scoping ──
        if ($currentUser) {
            if ($currentUser->isAdmin() && ! $currentUser->isSuperAdmin()) {
                $query->where('admin_id', $currentUser->id);
            } elseif ($currentUser->isSales()) {
                $userId  = $currentUser->id;
                $adminId = $currentUser->admin_id;
                $today   = now()->toDateString();

                if ($adminId) {
                    $query->where('admin_id', $adminId);
                }

                $query->where(function ($q) use ($userId, $today) {
                    $q->where('assigned_sales_id', $userId)
                      ->orWhereHas('visitSchedules', function ($sq) use ($userId, $today) {
                          $sq->where('user_id', $userId)
                             ->whereDate('scheduled_date', $today);
                      });
                });
            }
        }
        // ───────────────────────────────────────────────────────────────────────────────────

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%");
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $query->distinct()->orderBy('name');

        if (! empty($filters['all'])) {
            return $query->get();
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Get a single store with latest visit eager loaded.
     */
    public function getStore(int|Store $store): Store
    {
        if (is_numeric($store)) {
            $store = Store::findOrFail($store);
        }

        return $store->load(['latestVisit.user:id,name,phone']);
    }

    /**
     * Create a new store with optional immediate sales assignment and daily/single scheduling.
     *
     * @param  array{name: string, owner_name?: string|null, phone?: string|null, address: string, latitude?: float|null, longitude?: float|null, notes?: string|null, assigned_sales_id?: int|null, schedule_visit?: bool, scheduled_date?: string|null, is_daily?: bool, schedule_days?: int|null, schedule_notes?: string|null}  $data
     */
    public function createStore(array $data, ?User $creator = null): Store
    {
        return DB::transaction(function () use ($data, $creator) {
            $isDaily = ! empty($data['is_daily']);
            $assignedSalesId = ! empty($data['assigned_sales_id']) ? (int) $data['assigned_sales_id'] : null;

            $storeData = [
                'name'             => $data['name'],
                'owner_name'       => $data['owner_name'] ?? null,
                'phone'            => $data['phone'] ?? null,
                'address'          => $data['address'],
                'latitude'         => $data['latitude'] ?? null,
                'longitude'        => $data['longitude'] ?? null,
                'notes'            => $data['notes'] ?? null,
                'photo_path'       => isset($data['photo']) ? $this->imageService->compressAndStore($data['photo'], 'stores', null) : null,
                'assigned_sales_id'=> $assignedSalesId,
                'schedule_frequency' => $isDaily ? 'daily' : null,
                'created_by'       => $creator?->id,
                'admin_id'         => $creator ? ($creator->isAdmin() ? $creator->id : $creator->admin_id) : null,
                'is_active'        => true,
            ];

            $store = Store::create($storeData);

            // Handle immediate scheduling if sales is assigned and schedule is requested
            $scheduleVisit = ! empty($data['schedule_visit']) || $isDaily;
            if ($assignedSalesId && $scheduleVisit) {
                $startDate = ! empty($data['scheduled_date'])
                    ? Carbon::parse($data['scheduled_date'])->startOfDay()
                    : now()->startOfDay();

                $scheduleNotes = $data['schedule_notes'] ?? ($isDaily ? 'Kunjungan rutin harian' : null);

                if ($isDaily) {
                    $days = isset($data['schedule_days']) ? (int) $data['schedule_days'] : 30;
                    for ($i = 0; $i < $days; $i++) {
                        $dateStr = $startDate->copy()->addDays($i)->toDateString();
                        VisitSchedule::updateOrCreate(
                            [
                                'user_id' => $assignedSalesId,
                                'store_id' => $store->id,
                                'scheduled_date' => $dateStr,
                            ],
                            [
                                'sequence' => 1,
                                'status' => 'pending',
                                'notes' => $scheduleNotes,
                            ]
                        );
                    }
                } else {
                    VisitSchedule::updateOrCreate(
                        [
                            'user_id' => $assignedSalesId,
                            'store_id' => $store->id,
                            'scheduled_date' => $startDate->toDateString(),
                        ],
                        [
                            'sequence' => 1,
                            'status' => 'pending',
                            'notes' => $scheduleNotes,
                        ]
                    );
                }
            }

            return $store->load(['latestVisit.user:id,name,phone', 'assignedSales:id,name']);
        });
    }

    /**
     * Update store details.
     *
     * @param  array{name?: string, owner_name?: string|null, phone?: string|null, address?: string, latitude?: float|null, longitude?: float|null, notes?: string|null, assigned_sales_id?: int|null, is_daily?: bool, schedule_visit?: bool, scheduled_date?: string|null, is_active?: bool}  $data
     */
    public function updateStore(Store $store, array $data): Store
    {
        return DB::transaction(function () use ($store, $data) {
            $isDaily = isset($data['is_daily']) ? (bool) $data['is_daily'] : ($store->schedule_frequency === 'daily');

            if (array_key_exists('is_daily', $data)) {
                $data['schedule_frequency'] = $isDaily ? 'daily' : null;
            }

            // Handle photo upload
            if (isset($data['photo'])) {
                // Delete old photo from active disk (gracefully ignores errors)
                if ($store->photo_path) {
                    $this->imageService->delete($store->photo_path);
                }
                $data['photo_path'] = $this->imageService->compressAndStore($data['photo'], 'stores', null);
            }
            unset($data['photo']); // Don't pass UploadedFile to mass-assignment

            $store->update($data);

            $assignedSalesId = $store->assigned_sales_id;
            $scheduleVisit = ! empty($data['schedule_visit']) || (! empty($data['is_daily']) && $assignedSalesId);

            if ($assignedSalesId && $scheduleVisit) {
                $startDate = ! empty($data['scheduled_date'])
                    ? Carbon::parse($data['scheduled_date'])->startOfDay()
                    : now()->startOfDay();

                $scheduleNotes = $data['schedule_notes'] ?? ($isDaily ? 'Kunjungan rutin harian' : null);

                if ($isDaily) {
                    $days = isset($data['schedule_days']) ? (int) $data['schedule_days'] : 30;
                    for ($i = 0; $i < $days; $i++) {
                        $dateStr = $startDate->copy()->addDays($i)->toDateString();
                        VisitSchedule::updateOrCreate(
                            [
                                'user_id' => $assignedSalesId,
                                'store_id' => $store->id,
                                'scheduled_date' => $dateStr,
                            ],
                            [
                                'sequence' => 1,
                                'status' => 'pending',
                                'notes' => $scheduleNotes,
                            ]
                        );
                    }
                } else {
                    VisitSchedule::updateOrCreate(
                        [
                            'user_id' => $assignedSalesId,
                            'store_id' => $store->id,
                            'scheduled_date' => $startDate->toDateString(),
                        ],
                        [
                            'sequence' => 1,
                            'status' => 'pending',
                            'notes' => $scheduleNotes,
                        ]
                    );
                }
            }

            return $store->load(['latestVisit.user:id,name,phone', 'assignedSales:id,name']);
        });
    }

    /**
     * Delete a store together with its uploaded photo.
     */
    public function deleteStore(Store $store): void
    {
        if ($store->photo_path) {
            $this->imageService->delete($store->photo_path);
        }

        $store->delete();
    }
}
