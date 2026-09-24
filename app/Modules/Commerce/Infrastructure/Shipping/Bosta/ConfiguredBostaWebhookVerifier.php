<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Shipping\Bosta;

use App\Modules\Commerce\Domain\Contracts\BostaWebhookVerifierInterface;
use Illuminate\Http\Request;

/**
 * Production verifier placeholder.
 *
 * BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS — do not invent HMAC/header algorithms.
 * Rejects all webhooks until BOSTA_WEBHOOK_SIGNATURE_READY=true and official
 * documentation is implemented here.
 */
final class ConfiguredBostaWebhookVerifier implements BostaWebhookVerifierInterface
{
    public function verify(Request $request): bool
    {
        if (! (bool) config('bosta.webhook_signature_ready')) {
            return false;
        }

        $secret = (string) config('bosta.webhook_secret', '');
        if ($secret === '') {
            return false;
        }

        // Official signature comparison must be implemented from Bosta docs.
        // Intentionally not invented.
        return false;
    }
}
