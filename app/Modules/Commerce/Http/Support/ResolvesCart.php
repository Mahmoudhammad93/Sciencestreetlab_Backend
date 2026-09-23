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
    public const CART_SESSION_HEADER = 'X-Cart-Session';

    public function __construct(
        private readonly CartService $cartService,
    ) {}

    public function fromRequest(Request $request): Cart
    {
        $user = $this->resolveAuthenticatedUser($request);
        $guestSessionId = $this->resolveGuestSessionId($request);

        if ($user && $guestSessionId !== null) {
            return $this->cartService->mergeSessionCartIntoUserCart($user, $guestSessionId);
        }

        if ($user) {
            return $this->cartService->resolveCart($user, null);
        }

        return $this->cartService->resolveCart(null, $guestSessionId);
    }

    /**
     * Guest cart key from the SPA header (preferred) or Laravel session.
     */
    public function resolveGuestSessionId(Request $request): ?string
    {
        $header = $request->header(self::CART_SESSION_HEADER);

        if (is_string($header)) {
            $header = trim($header);
            if ($header !== '' && preg_match('/^[A-Za-z0-9_-]{8,128}$/', $header) === 1) {
                return $header;
            }
        }

        if ($request->hasSession()) {
            return $request->session()->getId();
        }

        return null;
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
