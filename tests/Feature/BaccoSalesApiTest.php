<?php

namespace Tests\Feature;

use App\Jobs\SaveStoreVisitJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\SalesInventory;
use App\Models\SalesInventoryItem;
use App\Models\Store;
use App\Models\StoreVisit;
use App\Models\User;
use App\Models\VisitSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BaccoSalesApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $sales1;
    protected User $sales2;
    protected Store $store;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin@bacco.test'],
            [
                'name' => 'Admin Test',
                'phone' => '081200000001',
                'role' => 'admin',
                'password' => 'password123',
                'is_active' => true,
            ]
        );

        $this->sales1 = User::firstOrCreate(
            ['email' => 'sales1@bacco.test'],
            [
                'name' => 'Sales Satu',
                'phone' => '081234567891',
                'role' => 'sales',
                'password' => 'password123',
                'is_active' => true,
            ]
        );

        $this->sales2 = User::firstOrCreate(
            ['email' => 'sales2@bacco.test'],
            [
                'name' => 'Sales Dua',
                'phone' => '081234567892',
                'role' => 'sales',
                'password' => 'password123',
                'is_active' => true,
            ]
        );

        $this->store = Store::firstOrCreate(
            ['name' => 'Toko Uji Coba'],
            [
                'owner_name' => 'Pak Joko',
                'phone' => '081999888777',
                'address' => 'Jl. Test No. 1',
                'latitude' => -7.7956,
                'longitude' => 110.3695,
                'created_by' => $this->admin->id,
                'is_active' => true,
            ]
        );

        $this->product = Product::firstOrCreate(
            ['sku' => 'TEST-PRD-01'],
            [
                'name' => 'Test Tembakau',
                'price' => 25000,
                'stock' => 50,
                'is_active' => true,
            ]
        );
    }

    public function test_auth_login_returns_token_and_user_profile(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'sales1@bacco.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'user' => ['id', 'name', 'email', 'phone', 'role'],
            ]);
    }

    public function test_admin_can_register_new_sales_user(): void
    {
        $uniqueEmail = 'sales_new_'.time().'@bacco.test';

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Sales Baru',
                'email' => $uniqueEmail,
                'phone' => '081912345678',
                'password' => 'secret123',
                'role' => 'sales',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.email', $uniqueEmail)
            ->assertJsonPath('user.role', 'sales');

        $this->assertDatabaseHas('users', [
            'email' => $uniqueEmail,
            'name' => 'Sales Baru',
            'phone' => '081912345678',
        ]);
    }

    public function test_admin_can_update_sales_user_password(): void
    {
        $newPassword = 'newsecret'.time();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/users/{$this->sales1->id}", [
                'password' => $newPassword,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'User berhasil diperbarui.');

        // Verify sales can login with the new password
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $this->sales1->email,
            'password' => $newPassword,
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_sales_cannot_register_user_forbidden(): void
    {
        $response = $this->actingAs($this->sales1, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Hacker User',
                'email' => 'hacker@bacco.test',
                'phone' => '089999999999',
                'password' => 'secret123',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_store_with_location(): void
    {
        $storeName = 'Toko Baru '.time();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/stores', [
                'name' => $storeName,
                'owner_name' => 'Pak Hendra',
                'phone' => '081122334455',
                'address' => 'Jl. Kaliurang KM 10',
                'latitude' => -7.7123456,
                'longitude' => 110.4123456,
                'notes' => 'Dekat minimarket',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('store.name', $storeName)
            ->assertJsonPath('store.latitude', -7.7123456);
    }

    public function test_admin_can_create_store_with_assigned_sales_and_daily_schedule(): void
    {
        $storeName = 'Toko Rutin '.time();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/stores', [
                'name' => $storeName,
                'owner_name' => 'Bu Susi',
                'phone' => '081299887766',
                'address' => 'Jl. Gejayan No. 45',
                'assigned_sales_id' => $this->sales1->id,
                'is_daily' => true,
                'schedule_days' => 7,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('store.name', $storeName)
            ->assertJsonPath('store.assigned_sales_id', $this->sales1->id)
            ->assertJsonPath('store.schedule_frequency', 'daily');

        $storeId = $response->json('store.id');

        // Verify that 7 daily schedules were created for sales1
        $this->assertEquals(
            7,
            \App\Models\VisitSchedule::where('user_id', $this->sales1->id)
                ->where('store_id', $storeId)
                ->count()
        );
    }

    public function test_admin_can_create_store_with_photo_uploaded_to_storage(): void
    {
        $disk = config('filesystems.default', 'public');
        Storage::fake($disk);

        $storeName = 'Toko Foto '.time();
        $photo = UploadedFile::fake()->image('store_front.jpg', 800, 600);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->post('/api/stores', [
                'name' => $storeName,
                'address' => 'Jl. Solo KM 9',
                'photo' => $photo,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('store.name', $storeName);

        $photoUrl = $response->json('store.photo_url');
        $this->assertNotNull($photoUrl);

        $store = \App\Models\Store::where('name', $storeName)->first();
        $this->assertNotNull($store->photo_path);
        $this->assertTrue(Storage::disk($disk)->exists($store->photo_path));
    }

    public function test_product_barcode_scan_increments_stock(): void
    {
        $initialStock = $this->product->stock;
        $newBarcode = 'SCAN-'.time();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/products/{$this->product->id}/scan-barcode", [
                'barcode' => $newBarcode,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('product.stock', $initialStock + 1)
            ->assertJsonPath('barcode.barcode', $newBarcode);

        $this->assertDatabaseHas('product_barcodes', [
            'product_id' => $this->product->id,
            'barcode' => $newBarcode,
            'status' => 'available',
        ]);
    }

    public function test_product_auto_generate_stock_barcodes(): void
    {
        $initialStock = $this->product->fresh()->stock;

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/products/{$this->product->id}/generate-stock", [
                'quantity' => 5,
                'prefix' => 'GEN',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('generated_count', 5)
            ->assertJsonPath('product.stock', $initialStock + 5);

        $this->assertEquals(5, count($response->json('barcodes')));
    }

    public function test_sales_can_record_visit_with_photo_and_label_shows_in_stores_list(): void
    {
        Storage::fake('public');

        $photo = UploadedFile::fake()->image('visit.jpg', 600, 600);

        $response = $this->actingAs($this->sales1, 'sanctum')
            ->postJson('/api/visits', [
                'store_id' => $this->store->id,
                'photo' => $photo,
                'notes' => 'Kunjungan pengecekan display produk',
                'latitude' => -7.7956,
                'longitude' => 110.3695,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('visit.store_id', $this->store->id)
            ->assertJsonPath('visit.sales_name', $this->sales1->name);

        $photoPath = $response->json('visit.photo_path');
        $this->assertNotNull($photoPath);
        $this->assertTrue(Storage::disk('public')->exists($photoPath));

        // Verify label in stores list
        $storeResponse = $this->actingAs($this->sales1, 'sanctum')
            ->getJson("/api/stores/{$this->store->id}");

        $storeResponse->assertStatus(200)
            ->assertJsonPath('store.is_visited', true);

        $label = $storeResponse->json('store.visited_label');
        $this->assertStringContainsString('Toko telah dikunjungi oleh '.$this->sales1->name, $label);
    }

    public function test_record_visit_dispatches_save_store_visit_job_to_queue(): void
    {
        Storage::fake('public');
        Queue::fake();

        $photo = UploadedFile::fake()->image('visit_queue.jpg', 400, 400);

        $response = $this->actingAs($this->sales1, 'sanctum')
            ->postJson('/api/visits', [
                'store_id' => $this->store->id,
                'photo'    => $photo,
                'notes'    => 'Queue test visit',
            ]);

        $response->assertStatus(201);

        Queue::assertPushed(SaveStoreVisitJob::class, function ($job) {
            return $job->data['user_id'] === $this->sales1->id &&
                   $job->data['store_id'] === $this->store->id &&
                   $job->data['notes'] === 'Queue test visit';
        });
    }

    public function test_save_store_visit_job_handles_saving_and_schedule_completion(): void
    {
        // Setup pending schedule
        $schedule = VisitSchedule::create([
            'user_id'        => $this->sales1->id,
            'store_id'       => $this->store->id,
            'scheduled_date' => now()->toDateString(),
            'status'         => 'pending',
        ]);

        $jobData = [
            'user_id'    => $this->sales1->id,
            'store_id'   => $this->store->id,
            'visited_at' => now()->toIso8601String(),
            'photo_path' => 'visits/test_direct.jpg',
            'notes'      => 'Direct job test',
            'latitude'   => -7.7956,
            'longitude'  => 110.3695,
        ];

        $job = new SaveStoreVisitJob($jobData);
        $visit = $job->handle();

        $this->assertInstanceOf(StoreVisit::class, $visit);
        $this->assertDatabaseHas('store_visits', [
            'id'       => $visit->id,
            'user_id'  => $this->sales1->id,
            'store_id' => $this->store->id,
            'notes'    => 'Direct job test',
        ]);

        $this->assertEquals('completed', $schedule->fresh()->status);
    }

    public function test_sales_cannot_create_pos_order_before_checkin_visit(): void
    {
        $newStore = Store::create([
            'name' => 'Toko Tanpa Kunjungan',
            'address' => 'Jl. Belum Dikunjungi',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->sales1, 'sanctum')
            ->postJson('/api/orders', [
                'store_id' => $newStore->id,
                'cash_paid' => 50000,
                'items' => [
                    ['product_id' => $this->product->id, 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['store_id']);
    }

    public function test_sales_can_create_pos_order_calculates_change_and_returns_receipt(): void
    {
        // Must check in first
        StoreVisit::create([
            'user_id'    => $this->sales1->id,
            'store_id'   => $this->store->id,
            'visited_at' => now(),
            'notes'      => 'Check in sebelum jualan',
        ]);

        // Sales MUST have taken canvas inventory for today before POS
        $carriedQty = 10;
        $inventory = SalesInventory::create([
            'user_id' => $this->sales1->id,
            'date'    => today()->toDateString(),
            'notes'   => 'Stok bawaan hari ini',
            'status'  => 'active',
        ]);
        SalesInventoryItem::create([
            'inventory_id'     => $inventory->id,
            'product_id'       => $this->product->id,
            'quantity_carried' => $carriedQty,
            'quantity_sold'    => 0,
        ]);

        // Deduct warehouse stock as the inventory store() would
        $this->product->decrement('stock', $carriedQty);

        $qty          = 2;
        $unitPrice    = (float) $this->product->price;
        $totalAmount  = $unitPrice * $qty;
        $cashPaid     = $totalAmount + 20000;
        $expectedChange = 20000;

        $response = $this->actingAs($this->sales1, 'sanctum')
            ->postJson('/api/orders', [
                'store_id'       => $this->store->id,
                'cash_paid'      => $cashPaid,
                'payment_method' => 'cash',
                'items'          => [
                    [
                        'product_id' => $this->product->id,
                        'quantity'   => $qty,
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.total_amount', fn ($val) => (float) $val === (float) $totalAmount)
            ->assertJsonPath('order.cash_paid', fn ($val) => (float) $val === (float) $cashPaid)
            ->assertJsonPath('order.change_due', fn ($val) => (float) $val === (float) $expectedChange)
            ->assertJsonStructure([
                'order',
                'receipt' => [
                    'order_id',
                    'invoice_number',
                    'store_name',
                    'total_amount',
                    'cash_paid',
                    'change_due',
                    'plain_text_58mm',
                    'esc_pos_hex',
                ],
            ]);

        // Carried stock sold should increase (not warehouse stock)
        $this->assertEquals($qty, $inventory->items()->where('product_id', $this->product->id)->value('quantity_sold'));

        // Receipt endpoint can be queried
        $orderId = $response->json('order.id');
        $receiptResponse = $this->actingAs($this->sales1, 'sanctum')
            ->getJson("/api/orders/{$orderId}/receipt");

        $receiptResponse->assertStatus(200)
            ->assertJsonPath('receipt.order_id', $orderId);
    }

    public function test_user_data_isolation_sales_only_sees_own_orders(): void
    {
        // Create an order for sales2
        $orderSales2 = Order::create([
            'invoice_number' => 'INV-TEST-SALES2-'.time(),
            'user_id' => $this->sales2->id,
            'store_id' => $this->store->id,
            'total_amount' => 50000,
            'cash_paid' => 50000,
            'change_due' => 0,
            'status' => 'completed',
        ]);

        // Sales1 queries orders -> must NOT see Sales2's order!
        $response = $this->actingAs($this->sales1, 'sanctum')
            ->getJson('/api/orders');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($orderSales2->id, $ids);

        // Sales1 tries to directly access Sales2's order -> must be denied!
        $forbiddenResponse = $this->actingAs($this->sales1, 'sanctum')
            ->getJson("/api/orders/{$orderSales2->id}");

        $forbiddenResponse->assertStatus(422);

        // Admin queries orders -> can see Sales2's order!
        $adminResponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/orders');

        $adminResponse->assertStatus(200);
        $adminIds = collect($adminResponse->json('data'))->pluck('id');
        $this->assertContains($orderSales2->id, $adminIds);
    }

    public function test_admin_revenue_report_and_kpi_dashboard(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/reports/revenue');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'summary' => [
                    'period_month',
                    'period_year',
                    'total_revenue',
                    'total_orders',
                    'average_order_value',
                    'total_target_revenue',
                    'target_achievement_percentage',
                ],
                'sales_breakdown',
            ]);
    }

    public function test_sales_my_target_progress(): void
    {
        $response = $this->actingAs($this->sales1, 'sanctum')
            ->getJson('/api/sales/my-target');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'kpi' => [
                    'user_id',
                    'sales_name',
                    'period_month',
                    'period_year',
                    'target_revenue',
                    'actual_revenue',
                    'revenue_remaining',
                    'revenue_achievement_percentage',
                    'target_visits',
                    'actual_visits',
                    'status',
                ],
            ]);
    }

    public function test_visit_schedules_listing_and_isolation(): void
    {
        $store = \App\Models\Store::first();

        // Create schedule for sales1
        \App\Models\VisitSchedule::create([
            'user_id' => $this->sales1->id,
            'store_id' => $store->id,
            'scheduled_date' => now()->toDateString(),
            'sequence' => 1,
            'status' => 'pending',
            'notes' => 'Rute Toko 1',
        ]);

        // Sales1 sees their own schedule
        $res1 = $this->actingAs($this->sales1, 'sanctum')->getJson('/api/schedules');
        $res1->assertStatus(200)
            ->assertJsonPath('data.0.store_id', $store->id)
            ->assertJsonPath('data.0.sequence', 1);

        // Sales2 does not see sales1's schedule
        $res2 = $this->actingAs($this->sales2, 'sanctum')->getJson('/api/schedules');
        $res2->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_bulk_schedule_creation(): void
    {
        $store2 = \App\Models\Store::create([
            'name' => 'Toko Uji Coba 2',
            'owner_name' => 'Pak Budi',
            'phone' => '081233333333',
            'address' => 'Jl. Uji Coba 2',
            'latitude' => -6.2088,
            'longitude' => 106.8456,
        ]);
        $stores = [$this->store->id, $store2->id];

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/schedules/bulk', [
            'user_id' => $this->sales1->id,
            'scheduled_date' => now()->addDay()->toDateString(),
            'store_ids' => $stores,
            'notes' => 'Rute Besok',
        ]);

        $response->assertStatus(201)
            ->assertJsonCount(2, 'schedules');
    }

    /** @test */
    public function test_sales_store_list_filtered_by_assignment_and_schedule(): void
    {
        // Store assigned to sales1
        $assignedStore = Store::create([
            'name'               => 'Toko Assigned Sales1',
            'address'            => 'Jl. Assigned',
            'is_active'          => true,
            'assigned_sales_id'  => $this->sales1->id,
        ]);

        // Store with today's schedule for sales1
        $scheduledStore = Store::create([
            'name'      => 'Toko Terjadwal Sales1',
            'address'   => 'Jl. Jadwal',
            'is_active' => true,
        ]);
        VisitSchedule::create([
            'user_id'        => $this->sales1->id,
            'store_id'       => $scheduledStore->id,
            'scheduled_date' => today()->toDateString(),
            'status'         => 'pending',
        ]);

        // Unrelated store (no assignment, no schedule for sales1)
        $unrelatedStore = Store::create([
            'name'      => 'Toko Lain',
            'address'   => 'Jl. Lain',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->sales1, 'sanctum')
            ->getJson('/api/stores');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($assignedStore->id, $ids, 'Toko assigned harus muncul');
        $this->assertContains($scheduledStore->id, $ids, 'Toko terjadwal hari ini harus muncul');
        $this->assertNotContains($unrelatedStore->id, $ids, 'Toko tidak terkait tidak boleh muncul');
    }

    /** @test */
    public function test_sales_inventory_deducts_warehouse_stock_and_pos_uses_carried_stock(): void
    {
        $initialWarehouseStock = $this->product->fresh()->stock; // e.g. 50
        $carriedQty = 10;

        // 1. Sales submits morning inventory → warehouse should decrease by carriedQty
        $inventoryResponse = $this->actingAs($this->sales1, 'sanctum')
            ->postJson('/api/sales/inventory', [
                'date'  => today()->toDateString(),
                'notes' => 'Stok bawaan pagi',
                'items' => [
                    ['product_id' => $this->product->id, 'quantity_carried' => $carriedQty],
                ],
            ]);

        $inventoryResponse->assertStatus(201);
        $this->assertEquals(
            $initialWarehouseStock - $carriedQty,
            $this->product->fresh()->stock,
            'Stok gudang harus berkurang sebesar stok yang diambil sales'
        );

        // 2. POS: sales tries to sell without check-in → rejected
        $posResponse = $this->actingAs($this->sales1, 'sanctum')
            ->postJson('/api/orders', [
                'store_id' => $this->store->id,
                'cash_paid' => 999999,
                'items'    => [['product_id' => $this->product->id, 'quantity' => 1]],
            ]);
        $posResponse->assertStatus(422)->assertJsonValidationErrors(['store_id']);

        // 3. After check-in, POS should work and deduct from carried stock (not warehouse)
        StoreVisit::create([
            'user_id'    => $this->sales1->id,
            'store_id'   => $this->store->id,
            'visited_at' => now(),
        ]);

        $qty          = 3;
        $unitPrice    = (float) $this->product->price;
        $totalAmount  = $unitPrice * $qty;

        $orderResponse = $this->actingAs($this->sales1, 'sanctum')
            ->postJson('/api/orders', [
                'store_id'  => $this->store->id,
                'cash_paid' => $totalAmount,
                'items'     => [['product_id' => $this->product->id, 'quantity' => $qty]],
            ]);

        $orderResponse->assertStatus(201);

        // Warehouse stock must NOT change again (already deducted at morning take)
        $this->assertEquals(
            $initialWarehouseStock - $carriedQty,
            $this->product->fresh()->stock,
            'Stok gudang TIDAK boleh berubah lagi saat POS sales'
        );

        // quantity_sold in carried inventory must increase
        $inventory = SalesInventory::where('user_id', $this->sales1->id)
            ->whereDate('date', today()->toDateString())
            ->with('items')
            ->first();

        $invItem = $inventory->items->where('product_id', $this->product->id)->first();
        $this->assertEquals($qty, $invItem->quantity_sold, 'quantity_sold di stok bawaan harus bertambah');

        // 4. POS: cannot sell more than remaining carried stock
        $remaining = $carriedQty - $qty; // e.g. 7
        $overLimitResponse = $this->actingAs($this->sales1, 'sanctum')
            ->postJson('/api/orders', [
                'store_id'  => $this->store->id,
                'cash_paid' => 9999999,
                'items'     => [['product_id' => $this->product->id, 'quantity' => $remaining + 1]],
            ]);

        $overLimitResponse->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    /** @test */
    public function test_sales_inventory_pos_products_endpoint(): void
    {
        $carriedQty = 8;

        // Create inventory
        $inv = SalesInventory::create([
            'user_id' => $this->sales1->id,
            'date'    => today()->toDateString(),
            'status'  => 'active',
        ]);
        SalesInventoryItem::create([
            'inventory_id'     => $inv->id,
            'product_id'       => $this->product->id,
            'quantity_carried' => $carriedQty,
            'quantity_sold'    => 2,
        ]);

        $response = $this->actingAs($this->sales1, 'sanctum')
            ->getJson('/api/sales/inventory/products');

        $response->assertStatus(200)
            ->assertJsonPath('has_inventory', true)
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.stock', $carriedQty - 2) // remaining = 6
            ->assertJsonPath('products.0.quantity_carried', $carriedQty)
            ->assertJsonPath('products.0.quantity_sold', 2);
    }

    /** @test */
    public function test_visits_pagination_and_caching_returns_paginator_without_serialization_errors(): void
    {
        StoreVisit::create([
            'user_id'    => $this->sales1->id,
            'store_id'   => $this->store->id,
            'visited_at' => now(),
            'notes'      => 'Visit test 1',
        ]);

        // First call: populates cache
        $response1 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visits?per_page=10&page=1');
        $response1->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        // Second call: hits cache, must NOT return __PHP_Incomplete_Class
        $response2 = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visits?per_page=10&page=1');
        $response2->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
        $this->assertEquals($response1->json('meta.total'), $response2->json('meta.total'));
    }

    /** @test */
    public function test_sales_cannot_fill_inventory_more_than_once_on_same_day_unless_date_changes(): void
    {
        $today = today()->toDateString();
        $tomorrow = today()->addDay()->toDateString();

        // 1. First submission on today succeeds
        $firstRes = $this->actingAs($this->sales2, 'sanctum')
            ->postJson('/api/sales/inventory', [
                'date'  => $today,
                'items' => [
                    ['product_id' => $this->product->id, 'quantity_carried' => 5],
                ],
            ]);
        $firstRes->assertStatus(201);

        // 2. Second submission on today fails with 422
        $secondRes = $this->actingAs($this->sales2, 'sanctum')
            ->postJson('/api/sales/inventory', [
                'date'  => $today,
                'items' => [
                    ['product_id' => $this->product->id, 'quantity_carried' => 10],
                ],
            ]);
        $secondRes->assertStatus(422)
            ->assertJsonPath('message', 'Stok barang bawaan untuk tanggal ini sudah pernah disimpan dan tidak dapat diubah lagi. Anda baru dapat mengisi kembali setelah berganti hari.');

        // 3. Submission on a different day (tomorrow) succeeds
        $tomorrowRes = $this->actingAs($this->sales2, 'sanctum')
            ->postJson('/api/sales/inventory', [
                'date'  => $tomorrow,
                'items' => [
                    ['product_id' => $this->product->id, 'quantity_carried' => 4],
                ],
            ]);
        $tomorrowRes->assertStatus(201);
    }

    /** @test */
    public function test_offline_data_batch_sync_is_idempotent(): void
    {
        $today = today()->toDateString();

        // Give sales2 carried inventory for today
        $this->actingAs($this->sales2, 'sanctum')
            ->postJson('/api/sales/inventory', [
                'date'  => $today,
                'items' => [
                    ['product_id' => $this->product->id, 'quantity_carried' => 20],
                ],
            ])->assertStatus(201);

        $visitUuid = 'visit-uuid-12345';
        $orderUuid = 'order-uuid-67890';

        $syncPayload = [
            'visits' => [
                [
                    'client_uuid' => $visitUuid,
                    'store_id'    => $this->store->id,
                    'notes'       => 'Offline visit test',
                    'visited_at'  => now()->toDateTimeString(),
                ],
            ],
            'orders' => [
                [
                    'client_uuid'    => $orderUuid,
                    'invoice_number' => 'INV-OFFLINE-TEST-001',
                    'store_id'       => $this->store->id,
                    'cash_paid'      => 100000,
                    'items'          => [
                        ['product_id' => $this->product->id, 'quantity' => 2],
                    ],
                ],
            ],
        ];

        // First sync call
        $response1 = $this->actingAs($this->sales2, 'sanctum')
            ->postJson('/api/sync/offline-data', $syncPayload);

        $response1->assertStatus(200)
            ->assertJsonPath('synced_visits.0.client_uuid', $visitUuid)
            ->assertJsonPath('synced_orders.0.client_uuid', $orderUuid);

        $this->assertDatabaseHas('store_visits', ['client_uuid' => $visitUuid]);
        $this->assertDatabaseHas('orders', ['client_uuid' => $orderUuid, 'invoice_number' => 'INV-OFFLINE-TEST-001']);

        // Second sync call (duplicate retry due to network drop)
        $response2 = $this->actingAs($this->sales2, 'sanctum')
            ->postJson('/api/sync/offline-data', $syncPayload);

        $response2->assertStatus(200);

        // Ensure ONLY 1 visit & 1 order created in DB
        $this->assertEquals(1, StoreVisit::where('client_uuid', $visitUuid)->count());
        $this->assertEquals(1, Order::where('client_uuid', $orderUuid)->count());
    }

    /** @test */
    public function test_multi_admin_rbac_data_isolation(): void
    {
        // 1. Admin 1 creates Admin 2
        $admin2Res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name'     => 'Admin Two',
                'email'    => 'admin2@bacco.test',
                'phone'    => '081299990000',
                'password' => 'password123',
                'role'     => 'admin',
            ]);
        $admin2Res->assertStatus(201);
        $admin2Id = $admin2Res->json('user.id');
        $admin2 = User::find($admin2Id);

        // 2. Admin 2 creates a store & a product under Admin 2 tenant scope
        $store2Res = $this->actingAs($admin2, 'sanctum')
            ->postJson('/api/stores', [
                'name'    => 'Toko Admin 2',
                'address' => 'Jl. Admin 2 No. 88',
            ]);
        $store2Res->assertStatus(201);
        $store2Id = $store2Res->json('store.id');

        $prod2Res = $this->actingAs($admin2, 'sanctum')
            ->postJson('/api/products', [
                'name'  => 'Rokok Admin 2',
                'price' => 25000,
                'initial_stock' => 10,
            ]);
        $prod2Res->assertStatus(201);
        $prod2Id = $prod2Res->json('product.id');

        // 3. Admin 1 checks stores and products list: must NOT see Admin 2's store or product!
        $admin1Stores = $this->actingAs($this->admin, 'sanctum')->getJson('/api/stores?all=1');
        $admin1Stores->assertStatus(200);
        $admin1StoreIds = collect($admin1Stores->json('data'))->pluck('id');
        $this->assertFalse($admin1StoreIds->contains($store2Id));

        $admin1Prods = $this->actingAs($this->admin, 'sanctum')->getJson('/api/products?all=1');
        $admin1Prods->assertStatus(200);
        $admin1ProdIds = collect($admin1Prods->json('data'))->pluck('id');
        $this->assertFalse($admin1ProdIds->contains($prod2Id));

        // 4. Admin 2 checks stores and products list: sees ONLY Admin 2's store & product
        $admin2Stores = $this->actingAs($admin2, 'sanctum')->getJson('/api/stores?all=1');
        $admin2Stores->assertStatus(200);
        $admin2StoreIds = collect($admin2Stores->json('data'))->pluck('id');
        $this->assertTrue($admin2StoreIds->contains($store2Id));
        $this->assertFalse($admin2StoreIds->contains($this->store->id));
    }

    /** @test */
    public function test_superadmin_can_list_all_users_and_manage_superadmin_role(): void
    {
        $superAdmin = User::factory()->create([
            'name'     => 'Super Admin',
            'email'    => 'superadmin@bacco.test',
            'phone'    => '081111111111',
            'role'     => 'superadmin',
            'admin_id' => null,
        ]);

        // Superadmin can list all users (including admin and sales from other tenants)
        $listRes = $this->actingAs($superAdmin, 'sanctum')->getJson('/api/admin/users');
        $listRes->assertStatus(200);

        $returnedIds = collect($listRes->json('data'))->pluck('id');
        $this->assertTrue($returnedIds->contains($superAdmin->id));
        $this->assertTrue($returnedIds->contains($this->admin->id));
        $this->assertTrue($returnedIds->contains($this->sales1->id));
        $this->assertTrue($returnedIds->contains($this->sales2->id));

        // Superadmin can create another superadmin or admin
        $createRes = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/admin/users', [
            'name'     => 'Second SuperAdmin',
            'email'    => 'super2@bacco.test',
            'phone'    => '081222222222',
            'password' => 'password123',
            'role'     => 'superadmin',
        ]);
        $createRes->assertStatus(201)
            ->assertJsonPath('user.role', 'superadmin');

        // Regular admin cannot create a superadmin
        $forbiddenCreateRes = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/users', [
            'name'     => 'Fake SuperAdmin',
            'email'    => 'fake@bacco.test',
            'phone'    => '081333333333',
            'password' => 'password123',
            'role'     => 'superadmin',
        ]);
        $forbiddenCreateRes->assertStatus(422);
    }
}
