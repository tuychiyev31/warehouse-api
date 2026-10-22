<?php

use App\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Warehouse API',
    'endpoints' => [
        ['method' => 'GET', 'path' => '/api/products'],
        ['method' => 'GET', 'path' => '/api/storage/quantities?date='.now()->toDateString()],
        ['method' => 'GET', 'path' => '/api/batches/profit'],
        ['method' => 'POST', 'path' => '/api/purchases'],
        ['method' => 'POST', 'path' => '/api/batches/{batch}/refunds'],
        ['method' => 'POST', 'path' => '/api/orders'],
        ['method' => 'POST', 'path' => '/api/orders/{order}/refunds'],
    ],
]));

Route::post('purchases', [InventoryController::class, 'purchase']);
Route::post('batches/{batch}/refunds', [InventoryController::class, 'refundPurchase'])->whereNumber('batch');
Route::get('products', [InventoryController::class, 'available']);
Route::post('orders', [InventoryController::class, 'order']);
Route::post('orders/{order}/refunds', [InventoryController::class, 'refundOrder'])->whereNumber('order');
Route::get('storage/quantities', [InventoryController::class, 'quantities']);
Route::get('batches/profit', [InventoryController::class, 'profit']);
