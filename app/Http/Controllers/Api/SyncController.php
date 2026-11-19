<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\StoreVisitResource;
use App\Services\OrderService;
use App\Services\VisitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SyncController extends Controller
{
    public function __construct(
        protected VisitService $visitService,
        protected OrderService $orderService
    ) {}

    /**
     * Synchronize offline visits and orders in batch.
     * Guaranteed idempotent using client_uuid.
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'visits'                  => ['nullable', 'array'],
            'visits.*.client_uuid'     => ['nullable', 'string', 'max:64'],
            'visits.*.store_id'        => ['required', 'integer', 'exists:stores,id'],
            'visits.*.photo_base64'    => ['nullable', 'string'],
            'visits.*.notes'           => ['nullable', 'string'],
            'visits.*.latitude'        => ['nullable', 'numeric'],
            'visits.*.longitude'       => ['nullable', 'numeric'],
            'visits.*.visited_at'      => ['nullable', 'string'],

            'orders'                   => ['nullable', 'array'],
            'orders.*.client_uuid'      => ['nullable', 'string', 'max:64'],
            'orders.*.invoice_number'   => ['nullable', 'string', 'max:64'],
            'orders.*.store_id'         => ['required', 'integer', 'exists:stores,id'],
            'orders.*.cash_paid'        => ['required', 'numeric', 'min:0'],
            'orders.*.payment_method'   => ['nullable', 'string'],
            'orders.*.notes'            => ['nullable', 'string'],
            'orders.*.created_at'       => ['nullable', 'string'],
            'orders.*.items'            => ['required', 'array', 'min:1'],
            'orders.*.items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'orders.*.items.*.quantity'   => ['required', 'integer', 'min:1'],
        ]);

        $syncedVisits = [];
        $visitErrors = [];

        // 1. Process visits FIRST so check-in exists for orders
        if (! empty($validated['visits'])) {
            foreach ($validated['visits'] as $visitData) {
                try {
                    $visit = $this->visitService->recordVisit($user, [
                        'client_uuid'  => $visitData['client_uuid'] ?? null,
                        'store_id'     => (int) $visitData['store_id'],
                        'photo_base64' => $visitData['photo_base64'] ?? null,
                        'notes'        => $visitData['notes'] ?? null,
                        'latitude'     => $visitData['latitude'] ?? null,
                        'longitude'    => $visitData['longitude'] ?? null,
                        'visited_at'   => $visitData['visited_at'] ?? null,
                    ]);

                    $syncedVisits[] = [
                        'client_uuid' => $visitData['client_uuid'] ?? null,
                        'id'          => $visit->id,
                        'visit'       => new StoreVisitResource($visit),
                    ];
                } catch (\Throwable $e) {
                    Log::warning('Sync visit error: ' . $e->getMessage());
                    $visitErrors[] = [
                        'client_uuid' => $visitData['client_uuid'] ?? null,
                        'error'       => $e->getMessage(),
                    ];
                }
            }
        }

        $syncedOrders = [];
        $orderErrors = [];

        // 2. Process orders NEXT
        if (! empty($validated['orders'])) {
            foreach ($validated['orders'] as $orderData) {
                try {
                    $order = $this->orderService->createPosOrder($user, [
                        'client_uuid'    => $orderData['client_uuid'] ?? null,
                        'invoice_number' => $orderData['invoice_number'] ?? null,
                        'store_id'       => (int) $orderData['store_id'],
                        'cash_paid'      => (float) $orderData['cash_paid'],
                        'payment_method' => $orderData['payment_method'] ?? 'cash',
                        'notes'          => $orderData['notes'] ?? null,
                        'created_at'     => $orderData['created_at'] ?? null,
                        'items'          => $orderData['items'],
                    ]);

                    $syncedOrders[] = [
                        'client_uuid'    => $orderData['client_uuid'] ?? null,
                        'id'             => $order->id,
                        'invoice_number' => $order->invoice_number,
                        'order'          => new OrderResource($order),
                    ];
                } catch (\Throwable $e) {
                    Log::warning('Sync order error: ' . $e->getMessage());
                    $orderErrors[] = [
                        'client_uuid' => $orderData['client_uuid'] ?? null,
                        'error'       => $e->getMessage(),
                    ];
                }
            }
        }

        return response()->json([
            'message' => 'Proses sinkronisasi data offline selesai.',
            'synced_visits' => $syncedVisits,
            'synced_orders' => $syncedOrders,
            'visit_errors'  => $visitErrors,
            'order_errors'  => $orderErrors,
        ]);
    }
}
