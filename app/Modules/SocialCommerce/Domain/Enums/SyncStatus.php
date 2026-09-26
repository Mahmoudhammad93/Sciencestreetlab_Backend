<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Domain\Enums;

enum SyncStatus: string
{
    case NeverSynced = 'never_synced';
    case Pending = 'pending';
    case Syncing = 'syncing';
    case Synced = 'synced';
    case PartiallySynced = 'partially_synced';
    case Failed = 'failed';

    public function label(): string
    {
        return (string) __('sales_channels.sync.'.$this->value);
    }
}
