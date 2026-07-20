<?php

declare(strict_types=1);

namespace App\Modules\Media\Infrastructure\Providers;

use App\Shared\Kernel\ModuleServiceProvider;

final class MediaServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Media';
    }

    public function register(): void
    {
        //
    }
}
