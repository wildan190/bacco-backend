<?php

namespace App\Services;

use App\Models\User;
use App\Models\VisitSchedule;
use Illuminate\Database\Eloquent\Collection;

class ScheduleService
{
    /**
     * Get schedules with user scoping.
     *
     * @param  array{user_id?: int, date?: string, status?: string}  $filters
     * @return Collection<int, VisitSchedule>
     */
    public function getSchedules(User $currentUser, array $filters = []): Collection
    {
        $query = VisitSchedule::query()
            ->with([
                'user:id,name,phone',
                'store:id,name,owner_name,phone,address,latitude,longitude,notes',
            ]);

        // Sales users can only see their own schedules
        if ($currentUser->isSales()) {
            $query->where('user_id', $currentUser->id);
        } elseif (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['date'])) {
            $query->whereDate('scheduled_date', $filters['date']);
        } else {
            // Default to today if no date specified
            $query->whereDate('scheduled_date', now()->toDateString());
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('sequence')->orderBy('id')->get();
    }

    /**
     * Create a single schedule entry.
     */
    public function createSchedule(array $data): VisitSchedule
    {
        $schedule = VisitSchedule::create([
            'user_id' => $data['user_id'],
            'store_id' => $data['store_id'],
            'scheduled_date' => $data['scheduled_date'],
            'sequence' => $data['sequence'] ?? 1,
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
        ]);

        return $schedule->load([
            'user:id,name,phone',
            'store:id,name,owner_name,phone,address,latitude,longitude',
        ]);
    }

    /**
     * Bulk assign stores to a sales rep for a given date with automatic sequence.
     *
     * @param  array<int, int>  $storeIds
     * @return Collection<int, VisitSchedule>
     */
    public function bulkAssign(int $userId, string $scheduledDate, array $storeIds, ?string $notes = null): Collection
    {
        $created = [];
        $seq = 1;

        foreach ($storeIds as $storeId) {
            $created[] = VisitSchedule::updateOrCreate(
                [
                    'user_id' => $userId,
                    'store_id' => $storeId,
                    'scheduled_date' => $scheduledDate,
                ],
                [
                    'sequence' => $seq++,
                    'status' => 'pending',
                    'notes' => $notes,
                ]
            );
        }

        return VisitSchedule::whereIn('id', collect($created)->pluck('id'))
            ->with([
                'user:id,name,phone',
                'store:id,name,owner_name,phone,address,latitude,longitude',
            ])
            ->orderBy('sequence')
            ->get();
    }

    /**
     * Update an existing schedule.
     */
    public function updateSchedule(VisitSchedule $schedule, array $data): VisitSchedule
    {
        $schedule->update($data);

        return $schedule->load([
            'user:id,name,phone',
            'store:id,name,owner_name,phone,address,latitude,longitude',
        ]);
    }

    /**
     * Delete a schedule.
     */
    public function deleteSchedule(VisitSchedule $schedule): bool
    {
        return $schedule->delete();
    }
}
