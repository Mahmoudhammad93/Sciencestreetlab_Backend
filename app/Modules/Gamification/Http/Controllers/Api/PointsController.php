<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Gamification\Application\Services\PointsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PointsController extends Controller
{
    public function __construct(
        private readonly PointsService $points,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $record = $this->points->forUser($request->user());

        $transactions = $record->transactions()
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($tx) => [
                'amount' => $tx->amount,
                'description' => $tx->description,
                'created_at' => $tx->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => [
                'total_points' => $record->total_points,
                'recent_transactions' => $transactions,
            ],
        ]);
    }
}
