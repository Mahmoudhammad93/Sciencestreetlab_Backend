<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Application\Services\GuestPurchaseClaimService;
use App\Modules\Identity\Http\Resources\UserAuthResource;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

final class GuestClaimController extends Controller
{
    public function __construct(
        private readonly GuestPurchaseClaimService $claims,
    ) {}

    public function show(Request $request, string $token): JsonResponse
    {
        if ($this->tooManyAttempts($request, 'guest-claim-inspect', 30)) {
            return response()->json(['message' => 'Too many attempts.'], 429);
        }

        return response()->json(['data' => $this->claims->inspect($token)]);
    }

    public function consume(Request $request, string $token): JsonResponse
    {
        if ($this->tooManyAttempts($request, 'guest-claim-consume', 10)) {
            return response()->json(['message' => 'Too many attempts.'], 429);
        }

        $actingUser = $request->user();
        $registration = null;

        if ($actingUser === null && ($request->filled('name') || $request->filled('password'))) {
            $registration = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::defaults()],
                'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
                'locale' => ['nullable', 'in:ar,en'],
            ]);
        }

        try {
            // Critical ownership mutations run inside GuestPurchaseClaimService::activate()
            // in a single DB transaction with post-condition assertions.
            $payload = $this->claims->activate($token, $actingUser, $registration);
        } catch (DomainException $e) {
            $status = str_contains($e->getMessage(), 'Authentication required') ? 401 : 422;

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $status === 401 ? 'CLAIM_LOGIN_REQUIRED' : 'CLAIM_FAILED',
            ], $status);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to activate this claim safely.',
                'code' => 'CLAIM_INVARIANT_VIOLATION',
            ], 500);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to activate this claim.',
                'code' => 'CLAIM_FAILED',
            ], 500);
        }

        if (! ($payload['visible_in_my_orders'] ?? false)) {
            report(new RuntimeException('Guest claim activation returned without My Orders visibility.'));

            return response()->json([
                'message' => 'Unable to activate this claim safely.',
                'code' => 'CLAIM_INVARIANT_VIOLATION',
            ], 500);
        }

        // Issue API token only AFTER successful committed activation (new registrations).
        $issuedToken = null;
        $responseUser = null;
        if ($payload['created_user'] ?? false) {
            $issuedToken = $payload['user']->createToken('api')->plainTextToken;
            $responseUser = $payload['user'];
        }

        return response()->json([
            'data' => [
                'order_number' => $payload['order']->order_number,
                'enrollment_ids' => $payload['enrollments'],
                'token' => $issuedToken,
                'user' => $responseUser ? new UserAuthResource($responseUser) : null,
                'course_ready' => (bool) ($payload['course_ready'] ?? false),
                'visible_in_my_orders' => true,
                'idempotent' => (bool) ($payload['idempotent'] ?? false),
            ],
        ]);
    }

    private function tooManyAttempts(Request $request, string $key, int $max): bool
    {
        $limiterKey = $key.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, $max)) {
            return true;
        }

        RateLimiter::hit($limiterKey, 60);

        return false;
    }
}
