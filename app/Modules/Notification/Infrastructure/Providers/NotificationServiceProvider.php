<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Providers;

use App\Shared\Kernel\ModuleServiceProvider;

final class NotificationServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Notification';
    }

    public function register(): void
    {
        //
    }
}
