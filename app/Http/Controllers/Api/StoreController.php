<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreStoreRequest;
use App\Http\Requests\Store\UpdateStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\StoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StoreController extends Controller
{
    public function __construct(
        protected StoreService $storeService
    ) {}

    /**
     * List all stores with visit status and label.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $stores = $this->storeService->getStores([
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'per_page' => (int) $request->query('per_page', 20),
            'all' => $request->boolean('all'),
        ], $request->user());

        return StoreResource::collection($stores);
    }

    /**
     * Create a new store (Admin only).
     */
    public function store(StoreStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo');
        }
        $store = $this->storeService->createStore($validated, $request->user());

        return response()->json([
            'message' => 'Data toko berhasil disimpan.',
            'store'   => new StoreResource($store),
        ], 201);
    }

    /**
     * Show store details with latest visit info.
     */
    public function show(int $id): JsonResponse
    {
        $store = $this->storeService->getStore($id);

        return response()->json([
            'store' => new StoreResource($store),
        ]);
    }

    /**
     * Update store details (Admin only).
     */
    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $validated = $request->validated();
        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo');
        }
        $updated = $this->storeService->updateStore($store, $validated);

        return response()->json([
            'message' => 'Data toko berhasil diperbarui.',
            'store'   => new StoreResource($updated),
        ]);
    }

    /**
     * Delete a store (Admin only).
     */
    public function destroy(Store $store): JsonResponse
    {
        $this->storeService->deleteStore($store);

        return response()->json([
            'message' => 'Toko berhasil dihapus.',
        ]);
    }
}
