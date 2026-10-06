<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Shipping\Bosta;

use App\Modules\Commerce\Domain\Contracts\BostaWebhookVerifierInterface;
use Illuminate\Http\Request;

/**
 * Official Bosta webhook auth: configurable custom header name + value
 * from the Bosta dashboard (or per-delivery webhookCustomHeaders).
 *
 * Compares received header to BOSTA_WEBHOOK_SECRET with hash_equals.
 * Accepts optional "Bearer <secret>" scheme without weakening the match.
 * Does NOT invent an HMAC signature algorithm.
 *
 * @see https://docs.bosta.co/docs/how-to/get-delivery-status-via-webhook/
 */
final class ConfiguredBostaWebhookVerifier implements BostaWebhookVerifierInterface
{
    public function verify(Request $request): bool
    {
        return $this->diagnose($request)['accepted'];
    }

    /**
     * Safe diagnostics for observability (never includes secret values).
     *
     * @return array{
     *     accepted: bool,
     *     auth_ready: bool,
     *     secret_configured: bool,
     *     header_name_expected: string,
     *     header_present: bool,
     *     header_empty: bool,
     *     has_bearer_scheme: bool,
     *     length_matches_secret: bool|null,
     *     reason: string
     * }
     */
    public function diagnose(Request $request): array
    {
        $headerName = trim((string) config('bosta.webhook_auth_header', 'Authorization'));
        $authReady = (bool) config('bosta.webhook_auth_ready', config('bosta.webhook_signature_ready'));
        $secret = (string) config('bosta.webhook_secret', '');
        $secretConfigured = $secret !== '';

        $base = [
            'accepted' => false,
            'auth_ready' => $authReady,
            'secret_configured' => $secretConfigured,
            'header_name_expected' => $headerName,
            'header_present' => false,
            'header_empty' => true,
            'has_bearer_scheme' => false,
            'length_matches_secret' => null,
            'reason' => 'unknown',
        ];

        if (! $authReady) {
            return array_merge($base, ['reason' => 'webhook_auth_not_ready']);
        }

        if (! $secretConfigured) {
            return array_merge($base, ['reason' => 'webhook_secret_not_configured']);
        }

        if ($headerName === '') {
            return array_merge($base, ['reason' => 'webhook_auth_header_not_configured']);
        }

        $received = (string) $request->header($headerName, '');
        $headerPresent = $request->headers->has($headerName);
        $hasBearer = (bool) preg_match('/^Bearer\s+\S+/i', $received);
        $candidate = $this->normalizeSecretCandidate($received);
        $lengthMatches = $candidate !== '' ? strlen($candidate) === strlen($secret) : null;

        $base = array_merge($base, [
            'header_present' => $headerPresent || $received !== '',
            'header_empty' => $received === '',
            'has_bearer_scheme' => $hasBearer,
            'length_matches_secret' => $lengthMatches,
        ]);

        if ($received === '') {
            return array_merge($base, ['reason' => 'missing_auth_header']);
        }

        foreach ($this->candidates($received) as $value) {
            if ($value !== '' && hash_equals($secret, $value)) {
                return array_merge($base, [
                    'accepted' => true,
                    'reason' => 'accepted',
                    'length_matches_secret' => true,
                ]);
            }
        }

        return array_merge($base, ['reason' => 'invalid_auth_header']);
    }

    /**
     * @return list<string>
     */
    private function candidates(string $received): array
    {
        $out = [$received];
        $normalized = $this->normalizeSecretCandidate($received);
        if ($normalized !== '' && $normalized !== $received) {
            $out[] = $normalized;
        }

        return $out;
    }

    private function normalizeSecretCandidate(string $received): string
    {
        $received = trim($received);
        if (preg_match('/^Bearer\s+(.+)$/i', $received, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return $received;
    }
}
