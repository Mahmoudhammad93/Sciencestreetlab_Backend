<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Contracts;

use Illuminate\Http\Request;

/**
 * Verifies Bosta webhook authenticity.
 *
 * BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS: production signature algorithm is not
 * invented here. FakeBostaWebhookVerifier is used in tests; production verifier
 * rejects until BOSTA_WEBHOOK_SIGNATURE_READY + secret + docs are supplied.
 */
interface BostaWebhookVerifierInterface
{
    public function verify(Request $request): bool;
}
