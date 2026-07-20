<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity Module API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('identity')->group(function (): void {
    Route::get('/health', fn () => response()->json(['module' => 'Identity', 'status' => 'ok']));
});
