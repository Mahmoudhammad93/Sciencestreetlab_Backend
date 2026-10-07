<?php

declare(strict_types=1);

use App\Modules\Observability\Http\Controllers\Api\ClientErrorController;
use Illuminate\Support\Facades\Route;

Route::post('/client-errors', [ClientErrorController::class, 'store'])
    ->middleware('throttle:client-errors');
