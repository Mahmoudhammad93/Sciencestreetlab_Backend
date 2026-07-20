<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Providers;

use App\Shared\Kernel\ModuleServiceProvider;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Identity';
    }

    public function register(): void
    {
        //
    }
}
