<?php

declare(strict_types=1);

namespace App\Modules\Mobile\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Mobile\Application\Services\MobileHomeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileHomeController extends Controller
{
    public function __construct(
        private readonly MobileHomeService $home,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->home->build($request->user()),
        ]);
    }
}
