<?php

declare(strict_types=1);

use App\Modules\Content\Http\Controllers\Api\BlogController;
use App\Modules\Content\Http\Controllers\Api\PageController;
use Illuminate\Support\Facades\Route;

Route::get('/pages/{slug}', [PageController::class, 'show']);
Route::get('/blog', [BlogController::class, 'index']);
Route::get('/blog/{slug}', [BlogController::class, 'show']);
