<?php

declare(strict_types=1);

namespace App\Modules\Content\Infrastructure\Providers;

use App\Shared\Kernel\ModuleServiceProvider;

final class ContentServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Content';
    }

    public function register(): void
    {
        //
    }
}
