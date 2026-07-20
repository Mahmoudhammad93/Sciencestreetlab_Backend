<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Media Module API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('media')->group(function (): void {
    Route::get('/health', fn () => response()->json(['module' => 'Media', 'status' => 'ok']));
});
