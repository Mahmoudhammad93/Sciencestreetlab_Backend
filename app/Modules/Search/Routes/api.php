<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Search Module API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('search')->group(function (): void {
    Route::get('/health', fn () => response()->json(['module' => 'Search', 'status' => 'ok']));
});
