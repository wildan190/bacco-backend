<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Visit\StoreVisitRequest;
use App\Http\Resources\StoreVisitResource;
use App\Models\Store;
use App\Services\VisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StoreVisitController extends Controller
{
    public function __construct(
        protected VisitService $visitService
    ) {}

    /**
     * List store visits.
     * Scoped: Sales only sees their own visits; Admin can see all or filter.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $visits = $this->visitService->getVisits($request->user(), [
            'store_id' => $request->query('store_id') ? (int) $request->query('store_id') : null,
            'user_id' => $request->query('user_id') ? (int) $request->query('user_id') : null,
            'date' => $request->query('date'),
            'search' => $request->query('search'),
            'has_photo' => $request->boolean('has_photo'),
            'per_page' => (int) $request->query('per_page', 20),
        ]);

        return StoreVisitResource::collection($visits);
    }

    /**
     * Record a new store visit with photo.
     */
    public function store(StoreVisitRequest $request): JsonResponse
    {
        $visit = $this->visitService->recordVisit($request->user(), [
            'store_id' => (int) $request->validated('store_id'),
            'photo' => $request->file('photo'),
            'photo_base64' => $request->validated('photo_base64'),
            'notes' => $request->validated('notes'),
            'latitude' => $request->validated('latitude'),
            'longitude' => $request->validated('longitude'),
            'visited_at' => $request->validated('visited_at'),
        ]);

        return response()->json([
            'message' => 'Kunjungan toko berhasil dicatat.',
            'visit' => new StoreVisitResource($visit),
        ], 201);
    }

    /**
     * Check if a specific store has been visited today and get label.
     */
    public function checkStatus(Store $store): JsonResponse
    {
        $status = $this->visitService->getStoreVisitStatus($store);

        return response()->json([
            'is_visited_today' => $status['is_visited_today'],
            'label' => $status['label'],
            'latest_visit' => $status['latest_visit'] ? new StoreVisitResource($status['latest_visit']) : null,
        ]);
    }
}
