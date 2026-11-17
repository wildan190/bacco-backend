<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkScheduleRequest;
use App\Http\Requests\StoreScheduleRequest;
use App\Http\Resources\VisitScheduleResource;
use App\Models\VisitSchedule;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    public function __construct(
        protected ScheduleService $scheduleService
    ) {}

    /**
     * List visit schedules (scoped to sales user, or all for admin).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $schedules = $this->scheduleService->getSchedules(
            $request->user(),
            $request->only(['user_id', 'date', 'status'])
        );

        return VisitScheduleResource::collection($schedules);
    }

    /**
     * Create a single visit schedule.
     */
    public function store(StoreScheduleRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (empty($data['user_id'])) {
            $data['user_id'] = $request->user()->id;
        }

        $schedule = $this->scheduleService->createSchedule($data);

        return response()->json([
            'message' => 'Jadwal kunjungan berhasil dibuat.',
            'schedule' => new VisitScheduleResource($schedule),
        ], 201);
    }

    /**
     * Bulk assign stores to a sales rep with sequence order.
     */
    public function bulk(BulkScheduleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $schedules = $this->scheduleService->bulkAssign(
            $validated['user_id'],
            $validated['scheduled_date'],
            $validated['store_ids'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'message' => 'Rute jadwal kunjungan berhasil di-generate.',
            'schedules' => VisitScheduleResource::collection($schedules),
        ], 201);
    }

    /**
     * Update visit schedule status or sequence.
     */
    public function update(Request $request, VisitSchedule $schedule): JsonResponse
    {
        $validated = $request->validate([
            'sequence' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:pending,completed,cancelled'],
            'notes' => ['nullable', 'string', 'max:500'],
            'scheduled_date' => ['sometimes', 'date'],
        ]);

        if (isset($validated['status']) && $validated['status'] === 'completed' && ! $schedule->completed_at) {
            $validated['completed_at'] = now();
        }

        $updated = $this->scheduleService->updateSchedule($schedule, $validated);

        return response()->json([
            'message' => 'Jadwal kunjungan berhasil diperbarui.',
            'schedule' => new VisitScheduleResource($updated),
        ]);
    }

    /**
     * Delete a visit schedule.
     */
    public function destroy(VisitSchedule $schedule): JsonResponse
    {
        $this->scheduleService->deleteSchedule($schedule);

        return response()->json([
            'message' => 'Jadwal kunjungan berhasil dihapus.',
        ]);
    }
}
