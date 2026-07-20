<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Support;

use App\Modules\Commerce\Application\Services\CartService;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use Illuminate\Http\Request;

final class ResolvesCart
{
    public function __construct(
        private readonly CartService $cartService,
    ) {}

    public function fromRequest(Request $request): Cart
    {
        $sessionId = null;

        if (! $request->user() && $request->hasSession()) {
            $sessionId = $request->session()->getId();
        }

        return $this->cartService->resolveCart($request->user(), $sessionId);
    }
}
