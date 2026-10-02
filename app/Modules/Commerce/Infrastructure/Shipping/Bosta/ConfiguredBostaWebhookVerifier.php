<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Shipping\Bosta;

use App\Modules\Commerce\Domain\Contracts\BostaWebhookVerifierInterface;
use Illuminate\Http\Request;

/**
 * Official Bosta webhook auth: custom Authorization header name + value
 * configured in the Bosta dashboard (or per-delivery webhookCustomHeaders).
 *
 * Compares received header to BOSTA_WEBHOOK_SECRET with hash_equals.
 * Does NOT invent an HMAC signature algorithm.
 *
 * @see https://docs.bosta.co/docs/how-to/get-delivery-status-via-webhook/
 */
final class ConfiguredBostaWebhookVerifier implements BostaWebhookVerifierInterface
{
    public function verify(Request $request): bool
    {
        if (! (bool) config('bosta.webhook_auth_ready', config('bosta.webhook_signature_ready'))) {
            return false;
        }

        $secret = (string) config('bosta.webhook_secret', '');
        if ($secret === '') {
            return false;
        }

        $headerName = trim((string) config('bosta.webhook_auth_header', 'Authorization'));
        if ($headerName === '') {
            return false;
        }

        $received = (string) $request->header($headerName, '');
        if ($received === '') {
            return false;
        }

        return hash_equals($secret, $received);
    }
}
