<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductService
{
    /**
     * Get list of products.
     *
     * @param  array{search?: string, in_stock?: bool, is_active?: bool, per_page?: int, all?: bool}  $filters
     * @return LengthAwarePaginator|Collection<int, Product>
     */
    public function getProducts(array $filters = [], ?User $currentUser = null): LengthAwarePaginator|Collection
    {
        $query = Product::query()
            ->with(['availableBarcodes:id,product_id,barcode,status']);

        if ($currentUser) {
            if ($currentUser->isAdmin() && ! $currentUser->isSuperAdmin()) {
                $query->where('admin_id', $currentUser->id);
            } elseif ($currentUser->isSales() && $currentUser->admin_id) {
                $query->where('admin_id', $currentUser->admin_id);
            }
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', function ($b) use ($search) {
                        $b->where('barcode', 'like', "%{$search}%");
                    });
            });
        }

        if (isset($filters['in_stock']) && $filters['in_stock']) {
            $query->where('stock', '>', 0);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $query->orderBy('name');

        if (! empty($filters['all'])) {
            return $query->get();
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Get product by ID.
     */
    public function getProduct(int|Product $product): Product
    {
        if (is_numeric($product)) {
            $product = Product::findOrFail($product);
        }

        return $product->load(['availableBarcodes:id,product_id,barcode,status']);
    }

    /**
     * Find a product by scanning its barcode.
     */
    public function findByBarcode(string $barcode): ?Product
    {
        $productBarcode = ProductBarcode::with(['product'])
            ->where('barcode', $barcode)
            ->first();

        return $productBarcode?->product;
    }

    /**
     * Create a new product.
     *
     * @param  array{name: string, sku?: string|null, description?: string|null, price: float, initial_stock?: int, barcodes?: array<string>}  $data
     */
    public function createProduct(array $data, ?User $creator = null): Product
    {
        return DB::transaction(function () use ($data, $creator) {
            $sku = $data['sku'] ?? 'BC-'.strtoupper(Str::random(6));

            $initialStock = 0;
            if (! empty($data['barcodes']) && is_array($data['barcodes'])) {
                $initialStock = count($data['barcodes']);
            } elseif (! empty($data['initial_stock'])) {
                $initialStock = (int) $data['initial_stock'];
            }

            $product = Product::create([
                'name'        => $data['name'],
                'sku'         => $sku,
                'description' => $data['description'] ?? null,
                'price'       => $data['price'],
                'stock'       => $initialStock,
                'admin_id'    => $creator ? ($creator->isAdmin() ? $creator->id : $creator->admin_id) : null,
                'is_active'   => true,
            ]);

            // If manual barcode list passed
            if (! empty($data['barcodes']) && is_array($data['barcodes'])) {
                $now = now();
                $rows = [];
                foreach ($data['barcodes'] as $barcode) {
                    $rows[] = [
                        'product_id' => $product->id,
                        'barcode' => trim($barcode),
                        'status' => 'available',
                        'created_by' => $creator?->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                ProductBarcode::insert($rows);
            } elseif ($initialStock > 0) {
                // Auto generate barcodes for initial stock
                $this->generateStock($product, $initialStock, null, $creator);
            }

            return $product->load(['availableBarcodes:id,product_id,barcode,status']);
        });
    }

    /**
     * Update product details.
     *
     * @param  array{name?: string, sku?: string, description?: string|null, price?: float, is_active?: bool}  $data
     */
    public function updateProduct(Product $product, array $data): Product
    {
        $product->update($data);

        return $product;
    }

    /**
     * Scan a single barcode into a product (+1 stock).
     *
     * @throws ValidationException
     */
    public function scanBarcode(Product $product, string $barcode, ?User $creator = null): array
    {
        $barcode = trim($barcode);

        $exists = ProductBarcode::where('barcode', $barcode)->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'barcode' => ["Barcode '{$barcode}' sudah terdaftar dalam sistem."],
            ]);
        }

        return DB::transaction(function () use ($product, $barcode, $creator) {
            $productBarcode = ProductBarcode::create([
                'product_id' => $product->id,
                'barcode' => $barcode,
                'status' => 'available',
                'created_by' => $creator?->id,
            ]);

            $product->increment('stock', 1);

            return [
                'product' => $product->fresh(['availableBarcodes:id,product_id,barcode,status']),
                'barcode' => $productBarcode,
                'message' => 'Barcode berhasil di-scan, stok bertambah 1.',
            ];
        });
    }

    /**
     * Auto generate barcodes for manual stock input.
     *
     * @return array{product: Product, generated_count: int, barcodes: array<string>}
     */
    public function generateStock(Product $product, int $quantity, ?string $prefix = null, ?User $creator = null): array
    {
        return DB::transaction(function () use ($product, $quantity, $prefix, $creator) {
            $prefix = $prefix ?: 'BC'.str_pad((string) $product->id, 4, '0', STR_PAD_LEFT);
            $now = now();
            $generatedBarcodes = [];
            $rows = [];

            for ($i = 0; $i < $quantity; $i++) {
                $uniqueCode = $prefix.date('ymdHis').str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT).Str::upper(Str::random(3));
                $generatedBarcodes[] = $uniqueCode;
                $rows[] = [
                    'product_id' => $product->id,
                    'barcode' => $uniqueCode,
                    'status' => 'available',
                    'created_by' => $creator?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            ProductBarcode::insert($rows);
            $product->increment('stock', $quantity);

            return [
                'product' => $product->fresh(['availableBarcodes:id,product_id,barcode,status']),
                'generated_count' => $quantity,
                'barcodes' => $generatedBarcodes,
            ];
        });
    }
}
