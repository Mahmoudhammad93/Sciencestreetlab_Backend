<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Domain\Enums;

enum ConnectionStatus: string
{
    case NotConnected = 'not_connected';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case Expired = 'expired';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return (string) __('sales_channels.connection.'.$this->value);
    }
}
