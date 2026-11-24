<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\GenerateStockRequest;
use App\Http\Requests\Product\ScanBarcodeRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductBarcodeResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService
    ) {}

    /**
     * List product catalog with stock counts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = $this->productService->getProducts([
            'search' => $request->query('search'),
            'in_stock' => $request->boolean('in_stock'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'per_page' => (int) $request->query('per_page', 20),
            'all' => $request->boolean('all'),
        ], $request->user());

        return ProductResource::collection($products);
    }

    /**
     * Create a new product (Admin only).
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->createProduct($request->validated(), $request->user());

        return response()->json([
            'message' => 'Produk berhasil ditambahkan.',
            'product' => new ProductResource($product),
        ], 201);
    }

    /**
     * Show product details with barcodes.
     */
    public function show(int $id): JsonResponse
    {
        $product = $this->productService->getProduct($id);

        return response()->json([
            'product' => new ProductResource($product),
        ]);
    }

    /**
     * Update product details (Admin only).
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $updated = $this->productService->updateProduct($product, $request->validated());

        return response()->json([
            'message' => 'Produk berhasil diperbarui.',
            'product' => new ProductResource($updated),
        ]);
    }

    /**
     * Scan barcode to add 1 stock to product (Admin only).
     */
    public function scanBarcode(ScanBarcodeRequest $request, Product $product): JsonResponse
    {
        $result = $this->productService->scanBarcode(
            $product,
            $request->validated('barcode'),
            $request->user()
        );

        return response()->json([
            'message' => $result['message'],
            'product' => new ProductResource($result['product']),
            'barcode' => new ProductBarcodeResource($result['barcode']),
        ]);
    }

    /**
     * Auto generate barcodes for manual stock increment (Admin only).
     */
    public function generateStock(GenerateStockRequest $request, Product $product): JsonResponse
    {
        $result = $this->productService->generateStock(
            $product,
            (int) $request->validated('quantity'),
            $request->validated('prefix'),
            $request->user()
        );

        return response()->json([
            'message' => "Berhasil generate {$result['generated_count']} barcode dan menambah stok.",
            'product' => new ProductResource($result['product']),
            'generated_count' => $result['generated_count'],
            'barcodes' => $result['barcodes'],
        ]);
    }

    /**
     * Lookup product by barcode string.
     */
    public function lookupBarcode(Request $request): JsonResponse
    {
        $barcode = $request->query('barcode');
        if (! $barcode) {
            return response()->json(['message' => 'Parameter barcode wajib diisi.'], 422);
        }

        $product = $this->productService->findByBarcode($barcode);

        if (! $product) {
            return response()->json(['message' => 'Produk dengan barcode ini tidak ditemukan.'], 404);
        }

        return response()->json([
            'product' => new ProductResource($product),
        ]);
    }
}
