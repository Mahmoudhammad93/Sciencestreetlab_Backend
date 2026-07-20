<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

final readonly class RefundResult
{
    public function __construct(
        public bool $success,
        public ?string $transactionId = null,
        public ?string $message = null,
    ) {}
}
