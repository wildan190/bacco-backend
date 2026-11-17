<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\SalesTarget;
use App\Models\Store;
use App\Models\StoreVisit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = Hash::make('password123');

        // 1. Create Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@bacco.test'],
            [
                'name' => 'Admin Utama',
                'phone' => '081200000001',
                'role' => 'admin',
                'password' => $password,
                'is_active' => true,
            ]
        );

        // 2. Create Sales Users
        $sales1 = User::firstOrCreate(
            ['email' => 'sales1@bacco.test'],
            [
                'name' => 'Budi Santoso',
                'phone' => '081234567891',
                'role' => 'sales',
                'password' => $password,
                'is_active' => true,
            ]
        );

        $sales2 = User::firstOrCreate(
            ['email' => 'sales2@bacco.test'],
            [
                'name' => 'Siti Rahma',
                'phone' => '081234567892',
                'role' => 'sales',
                'password' => $password,
                'is_active' => true,
            ]
        );

        // 3. Create Stores
        $store1 = Store::firstOrCreate(
            ['name' => 'Toko Tembakau Rezeki'],
            [
                'owner_name' => 'Haji Mansyur',
                'phone' => '081399887711',
                'address' => 'Jl. Malioboro No. 45, Yogyakarta',
                'latitude' => -7.7928,
                'longitude' => 110.3658,
                'notes' => 'Toko grosir langganan, buka pukul 08:00 - 21:00',
                'created_by' => $admin->id,
                'is_active' => true,
            ]
        );

        $store2 = Store::firstOrCreate(
            ['name' => 'Toko Berkah Jaya'],
            [
                'owner_name' => 'Pak Bambang',
                'phone' => '081388776622',
                'address' => 'Jl. Solo Km 8, Sleman, Yogyakarta',
                'latitude' => -7.7820,
                'longitude' => 110.4210,
                'notes' => 'Lokasi strategis dekat pasar',
                'created_by' => $admin->id,
                'is_active' => true,
            ]
        );

        $store3 = Store::firstOrCreate(
            ['name' => 'Warung Madura Barokah'],
            [
                'owner_name' => 'Cak Rozi',
                'phone' => '081377665533',
                'address' => 'Jl. Kaliurang Km 5 No. 12, Sleman',
                'latitude' => -7.7650,
                'longitude' => 110.3800,
                'notes' => 'Buka 24 jam',
                'created_by' => $admin->id,
                'is_active' => true,
            ]
        );

        $store4 = Store::firstOrCreate(
            ['name' => 'Kios Bacco Sentosa'],
            [
                'owner_name' => 'Bu Dewi',
                'phone' => '081366554422',
                'address' => 'Jl. Gejayan No. 28, Sleman',
                'latitude' => -7.7712,
                'longitude' => 110.3905,
                'notes' => 'Spesialis rokok linting & bacco flavours',
                'created_by' => $admin->id,
                'is_active' => true,
            ]
        );

        // 4. Create Products & Barcodes
        $productsData = [
            [
                'name' => 'Bacco Virginia Premium 50g',
                'sku' => 'BAC-VIR-050',
                'description' => 'Tembakau pilihan Virginia grade A aroma manis alami.',
                'price' => 35000,
                'stock' => 50,
                'barcodes' => ['899100100001', '899100100002', '899100100003'],
            ],
            [
                'name' => 'Bacco Clove Blend Menthol 50g',
                'sku' => 'BAC-CLV-050',
                'description' => 'Perpaduan tembakau kretek cengkeh dan sensasi dingin menthol.',
                'price' => 30000,
                'stock' => 45,
                'barcodes' => ['899100200001', '899100200002'],
            ],
            [
                'name' => 'Bacco Rolling Paper King Size',
                'sku' => 'BAC-PPR-KS',
                'description' => 'Kertas linting ultra thin slow burning kualitas tinggi.',
                'price' => 8000,
                'stock' => 120,
                'barcodes' => ['899100300001', '899100300002'],
            ],
            [
                'name' => 'Bacco Filter Tips Slim 6mm',
                'sku' => 'BAC-FLT-06',
                'description' => 'Gabus filter rokok kemasan 120 pcs.',
                'price' => 12000,
                'stock' => 80,
                'barcodes' => ['899100400001', '899100400002'],
            ],
            [
                'name' => 'Bacco Hand Rolling Roller 70mm',
                'sku' => 'BAC-RLR-70',
                'description' => 'Alat linting tembakau manual akrilik kokoh.',
                'price' => 20000,
                'stock' => 30,
                'barcodes' => ['899100500001'],
            ],
        ];

        $createdProducts = [];
        foreach ($productsData as $pData) {
            $product = Product::firstOrCreate(
                ['sku' => $pData['sku']],
                [
                    'name' => $pData['name'],
                    'description' => $pData['description'],
                    'price' => $pData['price'],
                    'stock' => $pData['stock'],
                    'is_active' => true,
                ]
            );

            foreach ($pData['barcodes'] as $barcode) {
                ProductBarcode::firstOrCreate(
                    ['barcode' => $barcode],
                    [
                        'product_id' => $product->id,
                        'status' => 'available',
                        'created_by' => $admin->id,
                    ]
                );
            }

            $createdProducts[] = $product;
        }

        // 5. Create Visits (Show visit labels)
        StoreVisit::firstOrCreate(
            [
                'user_id' => $sales1->id,
                'store_id' => $store1->id,
            ],
            [
                'visited_at' => Carbon::now()->subHours(2),
                'photo_path' => null,
                'notes' => 'Kunjungan rutin, cek stok tembakau dan promo bundling roll paper.',
                'latitude' => -7.7928,
                'longitude' => 110.3658,
            ]
        );

        StoreVisit::firstOrCreate(
            [
                'user_id' => $sales2->id,
                'store_id' => $store2->id,
            ],
            [
                'visited_at' => Carbon::now()->subHours(4),
                'photo_path' => null,
                'notes' => 'Toko ramai, pemilik minta restock varian menthol minggu depan.',
                'latitude' => -7.7820,
                'longitude' => 110.4210,
            ]
        );

        // 6. Create Targets for Current Month
        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;

        SalesTarget::updateOrCreate(
            [
                'user_id' => $sales1->id,
                'period_month' => $currentMonth,
                'period_year' => $currentYear,
            ],
            [
                'target_revenue' => 15000000, // Rp 15 juta
                'target_visits' => 40,
                'notes' => 'Target wilayah Yogyakarta Kota',
            ]
        );

        SalesTarget::updateOrCreate(
            [
                'user_id' => $sales2->id,
                'period_month' => $currentMonth,
                'period_year' => $currentYear,
            ],
            [
                'target_revenue' => 12000000, // Rp 12 juta
                'target_visits' => 35,
                'notes' => 'Target wilayah Sleman & Bantul',
            ]
        );

        // 7. Create Orders / POS Transactions
        $order1 = Order::firstOrCreate(
            ['invoice_number' => 'INV-'.date('Ymd').'-0001'],
            [
                'user_id' => $sales1->id,
                'store_id' => $store1->id,
                'total_amount' => 175000,
                'cash_paid' => 200000,
                'change_due' => 25000,
                'payment_method' => 'cash',
                'notes' => 'Pembelian grosir awal minggu',
                'status' => 'completed',
            ]
        );

        if ($order1->wasRecentlyCreated) {
            OrderItem::create([
                'order_id' => $order1->id,
                'product_id' => $createdProducts[0]->id,
                'quantity' => 3,
                'unit_price' => 35000,
                'subtotal' => 105000,
            ]);
            OrderItem::create([
                'order_id' => $order1->id,
                'product_id' => $createdProducts[1]->id,
                'quantity' => 2,
                'unit_price' => 30000,
                'subtotal' => 60000,
            ]);
            OrderItem::create([
                'order_id' => $order1->id,
                'product_id' => $createdProducts[3]->id,
                'quantity' => 1,
                'unit_price' => 10000,
                'subtotal' => 10000,
            ]);
        }

        $order2 = Order::firstOrCreate(
            ['invoice_number' => 'INV-'.date('Ymd').'-0002'],
            [
                'user_id' => $sales2->id,
                'store_id' => $store2->id,
                'total_amount' => 110000,
                'cash_paid' => 150000,
                'change_due' => 40000,
                'payment_method' => 'cash',
                'notes' => 'Restock reguler',
                'status' => 'completed',
            ]
        );

        if ($order2->wasRecentlyCreated) {
            OrderItem::create([
                'order_id' => $order2->id,
                'product_id' => $createdProducts[1]->id,
                'quantity' => 3,
                'unit_price' => 30000,
                'subtotal' => 90000,
            ]);
            OrderItem::create([
                'order_id' => $order2->id,
                'product_id' => $createdProducts[4]->id,
                'quantity' => 1,
                'unit_price' => 20000,
                'subtotal' => 20000,
            ]);
        }

        // --- 7. Visit Schedules (Route Planning) ---
        \App\Models\VisitSchedule::firstOrCreate(
            ['user_id' => $sales1->id, 'store_id' => $store1->id, 'scheduled_date' => now()->toDateString()],
            ['sequence' => 1, 'status' => 'completed', 'completed_at' => now(), 'notes' => 'Rute Pagi - Restock Utama']
        );
        \App\Models\VisitSchedule::firstOrCreate(
            ['user_id' => $sales1->id, 'store_id' => $store2->id, 'scheduled_date' => now()->toDateString()],
            ['sequence' => 2, 'status' => 'pending', 'notes' => 'Cek stok rokok kretek']
        );
        \App\Models\VisitSchedule::firstOrCreate(
            ['user_id' => $sales1->id, 'store_id' => $store3->id, 'scheduled_date' => now()->toDateString()],
            ['sequence' => 3, 'status' => 'pending', 'notes' => 'Tawarkan promo produk baru']
        );
        \App\Models\VisitSchedule::firstOrCreate(
            ['user_id' => $sales1->id, 'store_id' => $store4->id, 'scheduled_date' => now()->toDateString()],
            ['sequence' => 4, 'status' => 'pending', 'notes' => 'Tagihan & restock filter']
        );
    }
}
