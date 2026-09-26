<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Google;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Issues OAuth access tokens for Merchant API using a Google Cloud service account.
 *
 * Official flow: JWT bearer grant to https://oauth2.googleapis.com/token
 * with scope https://www.googleapis.com/auth/content
 *
 * @see https://developers.google.com/merchant/api/guides/quickstart/authentication
 * @see https://developers.google.com/merchant/api/guides/authorization/access-your-account
 */
final class GoogleServiceAccountTokenProvider
{
    /**
     * @param  array{client_email?: string, private_key?: string, private_key_id?: string|null}  $serviceAccount
     */
    public function accessToken(array $serviceAccount, ?string $cacheKey = null): string
    {
        $email = (string) ($serviceAccount['client_email'] ?? '');
        $privateKey = (string) ($serviceAccount['private_key'] ?? '');

        if ($email === '' || $privateKey === '') {
            throw new RuntimeException('invalid_service_account');
        }

        $key = $cacheKey ?? 'google-merchant-token:'.hash('sha256', $email);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $scope = (string) config('sales_channels.google_merchant.scope');
        $tokenUri = (string) config('sales_channels.google_merchant.token_uri');
        $assertion = $this->buildJwt($email, $privateKey, $scope, $tokenUri);

        $response = Http::asForm()
            ->timeout(20)
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('token_exchange_failed');
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        if ($token === '') {
            throw new RuntimeException('token_exchange_failed');
        }

        Cache::put($key, $token, now()->addSeconds(max(60, $expiresIn - 60)));

        return $token;
    }

    private function buildJwt(string $clientEmail, string $privateKey, string $scope, string $audience): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));

        $now = time();
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'scope' => $scope,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $unsigned = $header.'.'.$claims;
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new RuntimeException('invalid_service_account');
        }

        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new RuntimeException('invalid_service_account');
        }

        return $unsigned.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
