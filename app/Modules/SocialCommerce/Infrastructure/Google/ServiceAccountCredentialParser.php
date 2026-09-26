<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Infrastructure\Google;

/**
 * Parses and sanitizes Google Cloud service-account JSON for encrypted storage.
 * Never keeps unused secret fields beyond what JWT auth requires.
 */
final class ServiceAccountCredentialParser
{
    /**
     * @return array{
     *   type: string,
     *   project_id: ?string,
     *   private_key_id: ?string,
     *   private_key: string,
     *   client_email: string,
     *   client_id: ?string,
     *   token_uri: ?string
     * }
     */
    public function parse(string $rawJson): array
    {
        $decoded = json_decode($rawJson, true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('invalid_service_account');
        }

        $email = trim((string) ($decoded['client_email'] ?? ''));
        $privateKey = (string) ($decoded['private_key'] ?? '');
        $type = (string) ($decoded['type'] ?? '');

        if ($type !== 'service_account' || $email === '' || $privateKey === '') {
            throw new \InvalidArgumentException('invalid_service_account');
        }

        if (! str_contains($privateKey, 'BEGIN PRIVATE KEY')) {
            throw new \InvalidArgumentException('invalid_service_account');
        }

        return [
            'type' => 'service_account',
            'project_id' => isset($decoded['project_id']) ? (string) $decoded['project_id'] : null,
            'private_key_id' => isset($decoded['private_key_id']) ? (string) $decoded['private_key_id'] : null,
            'private_key' => $privateKey,
            'client_email' => $email,
            'client_id' => isset($decoded['client_id']) ? (string) $decoded['client_id'] : null,
            'token_uri' => isset($decoded['token_uri']) ? (string) $decoded['token_uri'] : null,
        ];
    }

    /**
     * Safe public metadata for technical admins (never includes private_key).
     *
     * @param  array<string, mixed>|null  $credentials
     * @return array{configured: bool, client_email: ?string, project_id: ?string}
     */
    public function publicMetadata(?array $credentials): array
    {
        if ($credentials === null || ($credentials['client_email'] ?? null) === null) {
            return [
                'configured' => false,
                'client_email' => null,
                'project_id' => null,
            ];
        }

        return [
            'configured' => true,
            'client_email' => (string) $credentials['client_email'],
            'project_id' => isset($credentials['project_id']) ? (string) $credentials['project_id'] : null,
        ];
    }
}
