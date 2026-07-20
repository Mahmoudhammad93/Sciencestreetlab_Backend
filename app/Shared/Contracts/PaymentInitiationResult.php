<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

final readonly class PaymentInitiationResult
{
    public function __construct(
        public string $iframeUrl,
        public int $paymentId,
        public ?string $gatewayOrderId = null,
    ) {}
}
