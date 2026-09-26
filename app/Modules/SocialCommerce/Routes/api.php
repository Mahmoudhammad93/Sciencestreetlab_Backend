<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Sales Channels are administered via Filament. No public customer API yet.
Route::middleware('auth:sanctum')->prefix('sales-channels')->group(function (): void {
    // Reserved for future authenticated staff/API tooling.
});
