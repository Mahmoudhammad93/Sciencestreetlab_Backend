<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Api\ProductController;
use App\Modules\Catalog\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

Route::prefix('products')->group(function (): void {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/{slug}', [ProductController::class, 'show']);
});

Route::middleware('auth:sanctum')->prefix('wishlist')->group(function (): void {
    Route::get('/', [WishlistController::class, 'index']);
    Route::post('/{product}', [WishlistController::class, 'toggle']);
});
