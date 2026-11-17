<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use App\Services\ReceiptPrinterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orderService,
        protected ReceiptPrinterService $receiptPrinterService
    ) {}

    /**
     * List POS transactions.
     * Scoped: Sales only sees their own transactions; Admin can see all.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $this->orderService->getOrders($request->user(), [
            'store_id' => $request->query('store_id') ? (int) $request->query('store_id') : null,
            'user_id' => $request->query('user_id') ? (int) $request->query('user_id') : null,
            'search' => $request->query('search'),
            'date' => $request->query('date'),
            'per_page' => (int) $request->query('per_page', 15),
        ]);

        return OrderResource::collection($orders);
    }

    /**
     * Create POS sales transaction.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->createPosOrder($request->user(), $request->validated());

        // Generate thermal mini receipt payload along with order response
        $receipt = $this->receiptPrinterService->formatReceipt($order);

        return response()->json([
            'message' => 'Transaksi POS berhasil diselesaikan.',
            'order' => new OrderResource($order),
            'receipt' => $receipt,
        ], 201);
    }

    /**
     * Get single transaction detail.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $order = $this->orderService->getOrder($id, $request->user());

        return response()->json([
            'order' => new OrderResource($order),
        ]);
    }

    /**
     * Get thermal Bluetooth mini printer receipt payload.
     */
    public function receipt(int $id, Request $request): JsonResponse
    {
        $order = $this->orderService->getOrder($id, $request->user());
        $receipt = $this->receiptPrinterService->formatReceipt($order);

        return response()->json([
            'receipt' => $receipt,
        ]);
    }
}
