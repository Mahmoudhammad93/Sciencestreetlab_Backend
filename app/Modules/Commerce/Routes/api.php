<?php

declare(strict_types=1);

use App\Modules\Commerce\Http\Controllers\Api\CartController;
use App\Modules\Commerce\Http\Controllers\Api\CartCouponController;
use App\Modules\Commerce\Http\Controllers\Api\CheckoutController;
use App\Modules\Commerce\Http\Controllers\Api\OrderController;
use App\Modules\Commerce\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('cart')->group(function (): void {
    Route::get('/', [CartController::class, 'show']);
    Route::post('/items', [CartController::class, 'addItem']);
    Route::put('/items/{item}', [CartController::class, 'updateItem']);
    Route::delete('/items/{item}', [CartController::class, 'removeItem']);
    Route::post('/coupon', [CartCouponController::class, 'apply']);
    Route::delete('/coupon', [CartCouponController::class, 'remove']);
});

Route::post('/payments/paymob/callback', [PaymentController::class, 'paymobCallback']);
Route::post('/payments/mock/{payment}/complete', [PaymentController::class, 'completeMock']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/checkout', [CheckoutController::class, 'store']);
    Route::post('/checkout/{order}/pay', [CheckoutController::class, 'pay']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{orderNumber}', [OrderController::class, 'show']);
});
