<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Shipping\Bosta;

use App\Modules\Commerce\Domain\Contracts\BostaWebhookVerifierInterface;
use Illuminate\Http\Request;

/**
 * Test verifier: accepts requests that carry X-Bosta-Test-Secret matching config,
 * or any request when no secret is configured in testing.
 */
final class FakeBostaWebhookVerifier implements BostaWebhookVerifierInterface
{
    public function verify(Request $request): bool
    {
        $expected = (string) config('bosta.webhook_secret', '');

        if ($expected === '') {
            return true;
        }

        return hash_equals($expected, (string) $request->header('X-Bosta-Test-Secret', ''));
    }
}
