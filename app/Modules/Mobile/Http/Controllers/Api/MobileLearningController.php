<?php

declare(strict_types=1);

namespace App\Modules\Mobile\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Mobile\Application\Services\MobileLearningDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileLearningController extends Controller
{
    public function __construct(
        private readonly MobileLearningDashboardService $dashboard,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->dashboard->build($request->user()),
        ]);
    }
}
