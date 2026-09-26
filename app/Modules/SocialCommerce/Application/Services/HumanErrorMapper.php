<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Application\Services;

final class HumanErrorMapper
{
    /**
     * @return array{title_key: string, message_key: string, action_key: string}
     */
    public function map(?string $technicalCode): array
    {
        return match ($technicalCode) {
            'token_refresh_failed', 'oauth_expired', 'invalid_grant' => [
                'title_key' => 'sales_channels.errors.connection_expired.title',
                'message_key' => 'sales_channels.errors.connection_expired.message',
                'action_key' => 'sales_channels.actions.reconnect',
            ],
            'missing_image_link', 'missing_main_image' => [
                'title_key' => 'sales_channels.errors.missing_image.title',
                'message_key' => 'sales_channels.errors.missing_image.message',
                'action_key' => 'sales_channels.actions.fix_product',
            ],
            'missing_price', 'invalid_price' => [
                'title_key' => 'sales_channels.errors.missing_price.title',
                'message_key' => 'sales_channels.errors.missing_price.message',
                'action_key' => 'sales_channels.actions.fix_product',
            ],
            'missing_title' => [
                'title_key' => 'sales_channels.errors.missing_title.title',
                'message_key' => 'sales_channels.errors.missing_title.message',
                'action_key' => 'sales_channels.actions.fix_product',
            ],
            'provider_not_configured', 'credentials_missing' => [
                'title_key' => 'sales_channels.errors.not_configured.title',
                'message_key' => 'sales_channels.errors.not_configured.message',
                'action_key' => 'sales_channels.actions.connect',
            ],
            'sync_in_progress' => [
                'title_key' => 'sales_channels.errors.sync_in_progress.title',
                'message_key' => 'sales_channels.errors.sync_in_progress.message',
                'action_key' => 'sales_channels.actions.view_activity',
            ],
            default => [
                'title_key' => 'sales_channels.errors.generic.title',
                'message_key' => 'sales_channels.errors.generic.message',
                'action_key' => 'sales_channels.actions.fix_issues',
            ],
        };
    }

    /**
     * @return array{title: string, message: string, action: string, code: ?string}
     */
    public function present(?string $technicalCode): array
    {
        $mapped = $this->map($technicalCode);

        return [
            'title' => (string) __($mapped['title_key']),
            'message' => (string) __($mapped['message_key']),
            'action' => (string) __($mapped['action_key']),
            'code' => $technicalCode,
        ];
    }
}
