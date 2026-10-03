<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Content\Http\Resources\HomeSlideResource;
use App\Modules\Content\Infrastructure\Persistence\Models\HomeSlide;
use Illuminate\Http\JsonResponse;

final class HomeSlideController extends Controller
{
    public function index(): JsonResponse
    {
        $slides = HomeSlide::query()
            ->active()
            ->ordered()
            ->get([
                'id',
                'image',
                'background_color',
                'link',
                'link_target',
                'sort_order',
                'display_duration_seconds',
            ]);

        return HomeSlideResource::collection($slides)
            ->response()
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }
}
