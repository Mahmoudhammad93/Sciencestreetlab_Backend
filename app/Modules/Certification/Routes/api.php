<?php

declare(strict_types=1);

use App\Modules\Certification\Http\Controllers\Api\CertificateController;
use Illuminate\Support\Facades\Route;

Route::get('/certificates/verify/{code}', [CertificateController::class, 'verify']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/certificates', [CertificateController::class, 'index']);
    Route::get('/certificates/{uuid}/download', [CertificateController::class, 'download']);
});
