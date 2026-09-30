<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Commerce\Application\Services\GuestPurchaseClaimService;
use App\Modules\Commerce\Application\Support\GuestTokenHasher;
use App\Modules\Identity\Http\Resources\UserAuthResource;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

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

        $user = $request->user();

        if (! $user) {
            $inspection = $this->claims->inspect($token);
            if (($inspection['status'] ?? '') === 'valid' && ($inspection['requires_registration'] ?? false)) {
                $validated = $request->validate([
                    'name' => ['required', 'string', 'max:255'],
                    'password' => ['required', 'confirmed', Password::defaults()],
                    'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
                    'locale' => ['nullable', 'in:ar,en'],
                ]);

                $email = GuestTokenHasher::normalizeEmail((string) ($inspection['email'] ?? ''));
                if ($email === '' || User::query()->where('email', $email)->exists()) {
                    return response()->json(['message' => 'Unable to register for this claim.'], 422);
                }

                $user = User::query()->create([
                    'name' => $validated['name'],
                    'email' => $email,
                    'phone' => $validated['phone'] ?? null,
                    'password' => Hash::make($validated['password']),
                    'locale' => $validated['locale'] ?? config('sciencestreet.default_locale'),
                ]);
            } else {
                return response()->json([
                    'message' => 'Authentication required to claim this purchase.',
                    'code' => 'CLAIM_LOGIN_REQUIRED',
                ], 401);
            }
        }

        try {
            $result = $this->claims->consume($token, $user);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $tokenPlain = null;
        if (! $request->user()) {
            $tokenPlain = $user->createToken('api')->plainTextToken;
        }

        return response()->json([
            'data' => [
                'order_number' => $result['order']->order_number,
                'enrollment_ids' => $result['enrollments'],
                'token' => $tokenPlain,
                'user' => $tokenPlain ? new UserAuthResource($user) : null,
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
