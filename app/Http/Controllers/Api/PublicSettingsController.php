<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SiteSettings;
use Illuminate\Http\JsonResponse;

final class PublicSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => SiteSettings::public(),
        ]);
    }
}
