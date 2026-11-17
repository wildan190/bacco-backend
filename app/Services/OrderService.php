<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\SalesInventory;
use App\Models\SalesInventoryItem;
use App\Models\StoreVisit;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * Get orders list scoped to user role to prevent data collisions.
     * Eager loads relationships to avoid N+1 query problem.
     *
     * @param  array{store_id?: int, user_id?: int, search?: string, per_page?: int, date?: string}  $filters
     */
    public function getOrders(User $currentUser, array $filters = []): LengthAwarePaginator
    {
        $query = Order::query()
            ->with([
                'user:id,name,phone',
                'store:id,name,address,phone',
                'items.product:id,name,sku,price',
            ])
            ->withCount('items');

        // Multi-tenant isolation: Sales users only see their own transactions
        if ($currentUser->isSales()) {
            $query->where('user_id', $currentUser->id);
        } elseif (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['store_id'])) {
            $query->where('store_id', $filters['store_id']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('store', fn ($s) => $s->where('name', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['date'])) {
            $query->whereDate('created_at', $filters['date']);
        }

        return $query->latest('id')->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get a single order with permission check.
     *
     * @throws ValidationException
     */
    public function getOrder(int $orderId, User $currentUser): Order
    {
        $query = Order::with([
            'user:id,name,phone,email',
            'store:id,name,address,phone,owner_name',
            'items.product:id,name,sku,price',
        ]);

        if ($currentUser->isSales()) {
            $query->where('user_id', $currentUser->id);
        }

        $order = $query->find($orderId);

        if (! $order) {
            throw ValidationException::withMessages([
                'order' => ['Transaksi tidak ditemukan atau Anda tidak memiliki akses.'],
            ]);
        }

        return $order;
    }

    /**
     * Create POS transaction with stock validation and deduction.
     * - For sales users: validates against and deducts from daily carried inventory (not master stock).
     * - For admin: deducts from master product stock as usual.
     *
     * @param  array{store_id: int, cash_paid: float, payment_method?: string|null, notes?: string|null, items: array<array{product_id: int, quantity: int}>}  $data
     *
     * @throws ValidationException
     */
    public function createPosOrder(User $salesUser, array $data): Order
    {
        // Enforce rule: Sales must take a visit photo (check-in) today at this store before POS is unlocked!
        if ($salesUser->isSales()) {
            $today = today()->toDateString();
            $hasVisited = StoreVisit::where('store_id', $data['store_id'])
                ->where('user_id', $salesUser->id)
                ->whereDate('visited_at', $today)
                ->exists() || Cache::has("user_visited_store_{$salesUser->id}_{$data['store_id']}_{$today}");

            if (! $hasVisited) {
                throw ValidationException::withMessages([
                    'store_id' => ['Anda harus melakukan check-in foto kunjungan ke toko ini terlebih dahulu sebelum dapat membuka transaksi penjualan POS.'],
                ]);
            }
        }

        $itemInputs = collect($data['items']);
        $productIds = $itemInputs->pluck('product_id')->unique();

        $totalAmount   = 0.0;
        $orderItemsData = [];

        // ── SALES USER: validate against carried inventory, NOT master stock ─────────
        if ($salesUser->isSales()) {
            $today = today()->toDateString();

            $inventory = SalesInventory::with('items.product')
                ->where('user_id', $salesUser->id)
                ->whereDate('date', $today)
                ->first();

            if (! $inventory) {
                throw ValidationException::withMessages([
                    'items' => ['Anda belum mengambil stok bawaan kanvas hari ini. Silakan input stok bawaan terlebih dahulu.'],
                ]);
            }

            // Map: product_id => SalesInventoryItem
            $inventoryItemMap = $inventory->items->keyBy('product_id');

            foreach ($itemInputs as $item) {
                $productId = (int) $item['product_id'];
                $qty       = (int) $item['quantity'];

                /** @var SalesInventoryItem|null $invItem */
                $invItem = $inventoryItemMap->get($productId);

                if (! $invItem) {
                    $productName = $invItem?->product?->name ?? "ID {$productId}";
                    throw ValidationException::withMessages([
                        'items' => ["Produk '{$productName}' tidak ada dalam stok bawaan kanvas Anda hari ini."],
                    ]);
                }

                if (! $invItem->product || ! $invItem->product->is_active) {
                    throw ValidationException::withMessages([
                        'items' => ["Produk '{$invItem->product?->name}' sedang tidak aktif."],
                    ]);
                }

                $remaining = max(0, $invItem->quantity_carried - ($invItem->quantity_sold ?? 0));

                if ($remaining < $qty) {
                    throw ValidationException::withMessages([
                        'items' => ["Sisa stok bawaan '{$invItem->product->name}' tidak mencukupi (Tersisa: {$remaining}, Diminta: {$qty})."],
                    ]);
                }

                $unitPrice = (float) $invItem->product->price;
                $subtotal  = $unitPrice * $qty;
                $totalAmount += $subtotal;

                $orderItemsData[] = [
                    'product_id'    => $productId,
                    'quantity'      => $qty,
                    'unit_price'    => $unitPrice,
                    'subtotal'      => $subtotal,
                    'inv_item_id'   => $invItem->id, // for carried stock decrement
                ];
            }

            $cashPaid = isset($data['cash_paid']) && (float) $data['cash_paid'] > 0
                ? (float) $data['cash_paid']
                : $totalAmount;
            $changeDue     = max(0.0, $cashPaid - $totalAmount);
            $invoiceNumber = 'INV-'.date('YmdHis').'-'.strtoupper(Str::random(4));

            return DB::transaction(function () use ($salesUser, $data, $invoiceNumber, $totalAmount, $cashPaid, $changeDue, $orderItemsData) {
                $order = Order::create([
                    'invoice_number' => $invoiceNumber,
                    'user_id'        => $salesUser->id,
                    'store_id'       => $data['store_id'],
                    'total_amount'   => $totalAmount,
                    'cash_paid'      => $cashPaid,
                    'change_due'     => $changeDue,
                    'payment_method' => $data['payment_method'] ?? 'cash',
                    'notes'          => $data['notes'] ?? null,
                    'status'         => 'completed',
                ]);

                foreach ($orderItemsData as $itemData) {
                    $order->items()->create([
                        'product_id' => $itemData['product_id'],
                        'quantity'   => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'subtotal'   => $itemData['subtotal'],
                    ]);

                    // Deduct from SALES carried inventory (not master warehouse stock)
                    SalesInventoryItem::where('id', $itemData['inv_item_id'])
                        ->increment('quantity_sold', $itemData['quantity']);
                }

                return $order->load([
                    'user:id,name,phone',
                    'store:id,name,address,phone',
                    'items.product:id,name,sku,price',
                ]);
            });
        }

        // ── ADMIN USER: validate against master product stock ───────────────────────
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($itemInputs as $item) {
            $productId = (int) $item['product_id'];
            $qty       = (int) $item['quantity'];

            /** @var Product|null $product */
            $product = $products->get($productId);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ["Produk dengan ID {$productId} tidak ditemukan."],
                ]);
            }

            if (! $product->is_active) {
                throw ValidationException::withMessages([
                    'items' => ["Produk '{$product->name}' sedang tidak aktif."],
                ]);
            }

            if ($product->stock < $qty) {
                throw ValidationException::withMessages([
                    'items' => ["Stok produk '{$product->name}' tidak mencukupi (Tersisa: {$product->stock}, Diminta: {$qty})."],
                ]);
            }

            $unitPrice = (float) $product->price;
            $subtotal  = $unitPrice * $qty;
            $totalAmount += $subtotal;

            $orderItemsData[] = [
                'product_id' => $product->id,
                'quantity'   => $qty,
                'unit_price' => $unitPrice,
                'subtotal'   => $subtotal,
            ];
        }

        $cashPaid = isset($data['cash_paid']) && (float) $data['cash_paid'] > 0
            ? (float) $data['cash_paid']
            : $totalAmount;
        $changeDue     = max(0.0, $cashPaid - $totalAmount);
        $invoiceNumber = 'INV-'.date('YmdHis').'-'.strtoupper(Str::random(4));

        return DB::transaction(function () use ($salesUser, $data, $invoiceNumber, $totalAmount, $cashPaid, $changeDue, $orderItemsData) {
            $order = Order::create([
                'invoice_number' => $invoiceNumber,
                'user_id'        => $salesUser->id,
                'store_id'       => $data['store_id'],
                'total_amount'   => $totalAmount,
                'cash_paid'      => $cashPaid,
                'change_due'     => $changeDue,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'notes'          => $data['notes'] ?? null,
                'status'         => 'completed',
            ]);

            foreach ($orderItemsData as $itemData) {
                $order->items()->create($itemData);

                // Deduct product stock (admin: master warehouse stock)
                Product::where('id', $itemData['product_id'])->decrement('stock', $itemData['quantity']);

                // Mark barcodes as sold if barcodes exist
                ProductBarcode::where('product_id', $itemData['product_id'])
                    ->where('status', 'available')
                    ->limit($itemData['quantity'])
                    ->update(['status' => 'sold']);
            }

            return $order->load([
                'user:id,name,phone',
                'store:id,name,address,phone',
                'items.product:id,name,sku,price',
            ]);
        });
    }
}
