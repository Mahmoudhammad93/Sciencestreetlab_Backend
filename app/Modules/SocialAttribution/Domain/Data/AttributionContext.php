<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Domain\Data;

/**
 * Request-layer attribution identity passed into checkout.
 * Never carries Filament/web admin session ownership.
 */
final class AttributionContext
{
    public function __construct(
        public readonly ?string $visitorKey,
        public readonly ?string $cartSessionId = null,
        public readonly ?int $authenticatedUserId = null,
    ) {}

    public function hasVisitor(): bool
    {
        return is_string($this->visitorKey) && $this->visitorKey !== '';
    }
}
