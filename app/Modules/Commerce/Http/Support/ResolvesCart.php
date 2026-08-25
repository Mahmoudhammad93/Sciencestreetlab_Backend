<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Support;

use App\Models\User;
use App\Modules\Commerce\Application\Services\CartService;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ResolvesCart
{
    public function __construct(
        private readonly CartService $cartService,
    ) {}

    public function fromRequest(Request $request): Cart
    {
        $user = $this->resolveAuthenticatedUser($request);

        if ($user && $request->hasSession()) {
            return $this->cartService->mergeSessionCartIntoUserCart(
                $user,
                $request->session()->getId(),
            );
        }

        if ($user) {
            return $this->cartService->resolveCart($user, null);
        }

        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        return $this->cartService->resolveCart(null, $sessionId);
    }

    private function resolveAuthenticatedUser(Request $request): ?User
    {
        if ($user = $request->user()) {
            return $user;
        }

        if (! $request->bearerToken()) {
            return null;
        }

        return Auth::guard('sanctum')->setRequest($request)->user();
    }
}
