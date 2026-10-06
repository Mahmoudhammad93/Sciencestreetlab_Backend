<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Support;

use App\Modules\Commerce\Http\Support\ResolvesCart;
use App\Modules\SocialAttribution\Domain\Data\AttributionContext;
use Illuminate\Http\Request;

final class ResolvesAttributionContext
{
    public function __construct(
        private readonly AttributionCookie $cookie,
        private readonly ResolvesCart $resolvesCart,
    ) {}

    public function fromRequest(Request $request): AttributionContext
    {
        $user = $request->user();

        return new AttributionContext(
            visitorKey: $this->cookie->read($request),
            cartSessionId: $this->resolvesCart->resolveGuestSessionId($request),
            authenticatedUserId: $user?->id,
        );
    }
}
