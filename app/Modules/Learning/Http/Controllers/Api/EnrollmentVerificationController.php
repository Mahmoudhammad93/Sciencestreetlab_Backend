<?php

declare(strict_types=1);

namespace App\Modules\Learning\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Learning\Application\Services\EnrollmentVerificationService;
use Illuminate\Http\JsonResponse;

final class EnrollmentVerificationController extends Controller
{
    public function __construct(
        private readonly EnrollmentVerificationService $verification,
    ) {}

    public function show(string $token): JsonResponse
    {
        $result = $this->verification->verify($token);

        return response()->json(
            ['data' => $result],
            $result['verified'] ? 200 : 404,
        );
    }
}
