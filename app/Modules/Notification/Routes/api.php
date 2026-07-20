<?php

declare(strict_types=1);

use App\Modules\Notification\Http\Controllers\Api\DeviceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/devices', [DeviceController::class, 'store']);
    Route::delete('/devices/{deviceId}', [DeviceController::class, 'destroy']);
});
