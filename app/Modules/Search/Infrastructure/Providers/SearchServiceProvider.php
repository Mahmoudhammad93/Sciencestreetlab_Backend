<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure\Providers;

use App\Shared\Kernel\ModuleServiceProvider;

final class SearchServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Search';
    }

    public function register(): void
    {
        //
    }
}
