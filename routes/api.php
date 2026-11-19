<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\StoreVisitController;
use App\Http\Controllers\Api\SalesInventoryController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Bacco Sales App
|--------------------------------------------------------------------------
*/

// Public Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
});

// Protected Routes (Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    // Auth Profile
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });

    // Stores (Catalog & Visits)
    Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
    Route::get('/stores/{id}', [StoreController::class, 'show'])->name('stores.show');
    Route::get('/stores/{store}/visit-status', [StoreVisitController::class, 'checkStatus'])->name('stores.visit-status');

    // Products (Catalog)
    Route::get('/products/lookup-barcode', [ProductController::class, 'lookupBarcode'])->name('products.lookup-barcode');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{id}', [ProductController::class, 'show'])->name('products.show');

    // Store Visits (Check-in & Photo)
    Route::get('/visits', [StoreVisitController::class, 'index'])->name('visits.index');
    Route::post('/visits', [StoreVisitController::class, 'store'])->name('visits.store');

    // Visit Schedules & Route Planning
    Route::get('/schedules', [ScheduleController::class, 'index'])->name('schedules.index');
    Route::post('/schedules', [ScheduleController::class, 'store'])->name('schedules.store');
    Route::put('/schedules/{schedule}', [ScheduleController::class, 'update'])->name('schedules.update');
    Route::delete('/schedules/{schedule}', [ScheduleController::class, 'destroy'])->name('schedules.destroy');

    // POS & Sales Orders
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{id}/receipt', [OrderController::class, 'receipt'])->name('orders.receipt');

    // Offline Data Synchronization
    Route::post('/sync/offline-data', [SyncController::class, 'sync'])->name('sync.offline-data');

    // Sales Personal KPI & Target
    Route::get('/sales/my-target', [DashboardController::class, 'salesTarget'])->name('sales.my-target');

    // Sales Inventory (Stok Bawaan Kanvas)
    Route::get('/sales/inventory', [SalesInventoryController::class, 'show'])->name('sales.inventory.show');
    Route::post('/sales/inventory', [SalesInventoryController::class, 'store'])->name('sales.inventory.store');
    Route::get('/sales/inventory/products', [SalesInventoryController::class, 'posProducts'])->name('sales.inventory.pos-products');
    Route::get('/sales/inventory/history', [SalesInventoryController::class, 'history'])->name('sales.inventory.history');

    // Admin Exclusive Routes
    Route::middleware('role:admin')->group(function () {
        // User Management
        Route::get('/admin/sales-users', [UserController::class, 'salesList'])->name('admin.sales-users');
        Route::apiResource('/admin/users', UserController::class)->names('admin.users');

        // Store Management
        Route::post('/stores', [StoreController::class, 'store'])->name('admin.stores.store');
        Route::put('/stores/{store}', [StoreController::class, 'update'])->name('admin.stores.update');
        Route::post('/stores/{store}', [StoreController::class, 'update'])->name('admin.stores.update.post');
        Route::delete('/stores/{store}', [StoreController::class, 'destroy'])->name('admin.stores.destroy');
        Route::post('/admin/schedules/bulk', [ScheduleController::class, 'bulk'])->name('admin.schedules.bulk');

        // Product & Stock Management
        Route::post('/products', [ProductController::class, 'store'])->name('admin.products.store');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('admin.products.update');
        Route::post('/products/{product}/scan-barcode', [ProductController::class, 'scanBarcode'])->name('admin.products.scan-barcode');
        Route::post('/products/{product}/generate-stock', [ProductController::class, 'generateStock'])->name('admin.products.generate-stock');

        // Revenue Reports & KPI Monitoring
        Route::get('/admin/reports/revenue', [DashboardController::class, 'adminRevenue'])->name('admin.reports.revenue');
        Route::get('/admin/targets', [DashboardController::class, 'listTargets'])->name('admin.targets.index');
        Route::post('/admin/targets', [DashboardController::class, 'saveTarget'])->name('admin.targets.store');

        // Admin: view sales inventories
        Route::get('/admin/inventory', [SalesInventoryController::class, 'adminIndex'])->name('admin.inventory.index');
    });
});
