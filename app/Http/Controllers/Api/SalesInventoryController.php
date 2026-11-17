<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SalesInventory;
use App\Models\SalesInventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesInventoryController extends Controller
{
    /**
     * GET /api/sales/inventory?date=YYYY-MM-DD
     * Sales: get own inventory for a date.
     */
    public function show(Request $request): JsonResponse
    {
        $date = $request->input('date', today()->toDateString());
        $user = $request->user();

        $inventory = SalesInventory::with(['items.product'])
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->first();

        return response()->json([
            'date'      => $date,
            'inventory' => $inventory ? $this->formatInventory($inventory) : null,
        ]);
    }

    /**
     * POST /api/sales/inventory
     * Sales: create or replace today's inventory (carried stock).
     * Deducts / restores warehouse stock (products.stock) atomically.
     * Body: { date, notes?, items: [{product_id, quantity_carried}] }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date'                     => ['required', 'date'],
            'notes'                    => ['nullable', 'string', 'max:500'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity_carried' => ['required', 'integer', 'min:0'],
        ]);

        $user = $request->user();

        // Enforce rule: Sales can only submit inventory once per day.
        $existingInventory = SalesInventory::where('user_id', $user->id)
            ->whereDate('date', $validated['date'])
            ->first();

        if ($existingInventory) {
            return response()->json([
                'message' => 'Stok barang bawaan untuk tanggal ini sudah pernah disimpan dan tidak dapat diubah lagi. Anda baru dapat mengisi kembali setelah berganti hari.',
            ], 422);
        }

        // Load all products in one query
        $productIds = collect($validated['items'])->pluck('product_id')->unique();
        $products   = Product::whereIn('id', $productIds)->get()->keyBy('id');

        // Build requested map
        $requestedCarried = [];
        foreach ($validated['items'] as $item) {
            $requestedCarried[(int)$item['product_id']] = (int)$item['quantity_carried'];
        }

        // Validate: requested carried stock must not exceed available warehouse stock
        $errors = [];
        foreach ($requestedCarried as $productId => $qty) {
            if ($qty <= 0) {
                continue;
            }
            $product = $products->get($productId);
            if ($product && $product->stock < $qty) {
                $errors[] = "Stok \"{$product->name}\" tidak cukup. "
                    . "Tersedia di gudang: {$product->stock}, diminta: {$qty}.";
            }
        }
        if (!empty($errors)) {
            return response()->json([
                'message' => 'Jumlah yang dibawa melebihi stok yang tersedia di gudang.',
                'errors'  => $errors,
            ], 422);
        }

        DB::transaction(function () use ($user, $validated, $requestedCarried, &$inventory) {
            // Create header
            $inventory = SalesInventory::create([
                'user_id' => $user->id,
                'date'    => $validated['date'],
                'notes'   => $validated['notes'] ?? null,
                'status'  => 'active',
            ]);

            // Deduct master warehouse stock
            foreach ($requestedCarried as $productId => $qty) {
                if ($qty <= 0) {
                    continue;
                }
                Product::where('id', $productId)->decrement('stock', $qty);
            }

            $items = collect($requestedCarried)
                ->filter(fn ($qty) => $qty > 0)
                ->map(fn ($qty, $productId) => [
                    'inventory_id'     => $inventory->id,
                    'product_id'       => $productId,
                    'quantity_carried' => $qty,
                    'quantity_sold'    => 0,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ])
                ->values()
                ->toArray();

            if (!empty($items)) {
                SalesInventoryItem::insert($items);
            }

            $inventory->load('items.product');
        });

        // Invalidate cached inventory histories
        Cache::increment('inventory_cache_version');

        return response()->json([
            'message'   => 'Stok bawaan berhasil disimpan. Stok gudang telah diperbarui.',
            'inventory' => $this->formatInventory($inventory),
        ], 201);
    }

    /**
     * GET /api/sales/inventory/products
     * Sales: list products WITH remaining carried stock today (for POS).
     */
    public function posProducts(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = today()->toDateString();

        $inventory = SalesInventory::with(['items.product'])
            ->where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();

        if (!$inventory) {
            return response()->json([
                'has_inventory' => false,
                'message'       => 'Anda belum mengambil stok bawaan kanvas hari ini. Silakan input stok bawaan terlebih dahulu.',
                'products'      => [],
            ]);
        }

        $products = $inventory->items
            ->filter(fn ($item) => $item->product !== null)
            ->map(function ($item) {
                $remaining = max(0, $item->quantity_carried - ($item->quantity_sold ?? 0));
                return [
                    'id'               => $item->product->id,
                    'name'             => $item->product->name,
                    'sku'              => $item->product->sku,
                    'price'            => $item->product->price,
                    'is_active'        => $item->product->is_active,
                    'stock'            => $remaining,          // remaining carried stock
                    'quantity_carried' => $item->quantity_carried,
                    'quantity_sold'    => $item->quantity_sold ?? 0,
                    'description'      => $item->product->description,
                ];
            })
            ->filter(fn ($p) => $p['stock'] > 0) // only items with remaining stock
            ->values();

        return response()->json([
            'has_inventory' => true,
            'inventory_id'  => $inventory->id,
            'products'      => $products,
        ]);
    }

    /**
     * GET /api/sales/inventory/history?page=&per_page=&start_date=&end_date=
     * Sales: list own inventory history with server-side pagination & caching.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 15);
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $cacheVersion = (int) Cache::get('inventory_cache_version', 1);
        $cacheKey = "sales_inv_hist_v{$cacheVersion}_u{$user->id}_p{$page}_pp{$perPage}_s{$startDate}_e{$endDate}";

        $result = Cache::remember($cacheKey, 300, function () use ($user, $perPage, $startDate, $endDate) {
            $query = SalesInventory::with(['items.product'])
                ->where('user_id', $user->id)
                ->orderByDesc('date');

            if (!empty($startDate)) {
                $query->whereDate('date', '>=', $startDate);
            }

            if (!empty($endDate)) {
                $query->whereDate('date', '<=', $endDate);
            }

            $paginated = $query->paginate($perPage);

            $formatted = $paginated->getCollection()->map(fn ($inv) => $this->formatInventory($inv))->values()->all();

            return [
                'inventories' => $formatted,
                'data'        => $formatted,
                'meta'        => [
                    'current_page' => $paginated->currentPage(),
                    'last_page'    => $paginated->lastPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                    'has_more'     => $paginated->hasMorePages(),
                ],
            ];
        });

        return response()->json($result);
    }

    /**
     * GET /api/admin/inventory?page=&per_page=&user_id=&date=&start_date=&end_date=
     * Admin: view any or all sales reps' inventory history with server-side pagination & caching.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 15);
        $userId = $request->filled('user_id') ? $request->integer('user_id') : null;
        $date = $request->input('date');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $cacheVersion = (int) Cache::get('inventory_cache_version', 1);
        $cacheKey = "admin_inv_hist_v{$cacheVersion}_u{$userId}_d{$date}_p{$page}_pp{$perPage}_s{$startDate}_e{$endDate}";

        $result = Cache::remember($cacheKey, 300, function () use ($userId, $date, $startDate, $endDate, $perPage) {
            $query = SalesInventory::with(['items.product', 'user'])
                ->orderByDesc('date');

            if (!empty($userId)) {
                $query->where('user_id', $userId);
            }

            if (!empty($date)) {
                $query->where('date', $date);
            }

            if (!empty($startDate)) {
                $query->whereDate('date', '>=', $startDate);
            }

            if (!empty($endDate)) {
                $query->whereDate('date', '<=', $endDate);
            }

            $paginated = $query->paginate($perPage);

            $formatted = $paginated->getCollection()->map(fn ($inv) => array_merge(
                $this->formatInventory($inv),
                [
                    'user_name'  => $inv->user?->name ?? 'Sales',
                    'user_id'    => $inv->user_id,
                    'user_phone' => $inv->user?->phone,
                ]
            ))->values()->all();

            return [
                'inventories' => $formatted,
                'data'        => $formatted,
                'meta'        => [
                    'current_page' => $paginated->currentPage(),
                    'last_page'    => $paginated->lastPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                    'has_more'     => $paginated->hasMorePages(),
                ],
            ];
        });

        return response()->json($result);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function formatInventory(SalesInventory $inventory): array
    {
        $items = $inventory->items->map(fn ($item) => [
            'id'                => $item->id,
            'product_id'        => $item->product_id,
            'product_name'      => $item->product?->name ?? '-',
            'product_sku'       => $item->product?->sku ?? '-',
            'product_stock'     => $item->product?->stock ?? 0,
            'quantity_carried'  => (int) $item->quantity_carried,
            'quantity_sold'     => (int) ($item->quantity_sold ?? 0),
            'quantity_returned' => (int) ($item->quantity_returned ?? 0),
            'remaining'         => max(0, (int) $item->quantity_carried - (int) ($item->quantity_sold ?? 0)),
        ])->values();

        $totalCarried = $items->sum('quantity_carried');
        $totalSold    = $items->sum('quantity_sold');

        return [
            'id'              => $inventory->id,
            'date'            => $inventory->date->toDateString(),
            'notes'           => $inventory->notes,
            'status'          => $inventory->status ?? 'active',
            'settled_at'      => $inventory->settled_at?->toIso8601String(),
            'items'           => $items,
            'total_carried'   => $totalCarried,
            'total_sold'      => $totalSold,
            'total_remaining' => max(0, $totalCarried - $totalSold),
        ];
    }
}
