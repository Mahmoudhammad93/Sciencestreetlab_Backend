<?php

declare(strict_types=1);

use App\Modules\Commerce\Http\Controllers\Api\BostaWebhookController;
use App\Modules\Commerce\Http\Controllers\Api\CartController;
use App\Modules\Commerce\Http\Controllers\Api\CartCouponController;
use App\Modules\Commerce\Http\Controllers\Api\CheckoutController;
use App\Modules\Commerce\Http\Controllers\Api\OrderController;
use App\Modules\Commerce\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('cart')->middleware('auth.optional')->group(function (): void {
    Route::get('/', [CartController::class, 'show']);
    Route::post('/items', [CartController::class, 'addItem']);
    Route::put('/items/{item}', [CartController::class, 'updateItem']);
    Route::delete('/items/{item}', [CartController::class, 'removeItem']);
    Route::post('/coupon', [CartCouponController::class, 'apply']);
    Route::delete('/coupon', [CartCouponController::class, 'remove']);
});

Route::post('/webhooks/bosta', BostaWebhookController::class);

Route::post('/payments/paymob/callback', [PaymentController::class, 'paymobCallback']);
Route::post('/payments/fawaterak/webhook', [PaymentController::class, 'fawaterakWebhook']);
Route::get('/payments/fawaterak/callback', [PaymentController::class, 'fawaterakCallback']);
Route::get('/payments/fawaterak/confirm', [PaymentController::class, 'fawaterakConfirm']);
// Kept for rollback: only reachable while commerce.payment_gateway = myfatoorah.
Route::get('/payments/myfatoorah/callback', [PaymentController::class, 'myfatoorahCallback']);
Route::get('/payments/myfatoorah/confirm', [PaymentController::class, 'myfatoorahConfirm']);
Route::post('/payments/mock/{payment}/complete', [PaymentController::class, 'completeMock']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/checkout', [CheckoutController::class, 'store']);
    Route::post('/checkout/{order}/pay', [CheckoutController::class, 'pay']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{orderNumber}', [OrderController::class, 'show']);
});