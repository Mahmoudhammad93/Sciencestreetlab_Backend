<?php

declare(strict_types=1);

namespace App\Shared\Kernel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

abstract class ModuleServiceProvider extends ServiceProvider
{
    abstract protected function moduleName(): string;

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath('Infrastructure/Persistence/Migrations'));

        Route::prefix('api/v1')
            ->middleware('api')
            ->group($this->modulePath('Routes/api.php'));
    }

    protected function modulePath(string $path = ''): string
    {
        $base = app_path('Modules/'.$this->moduleName());

        return $path === '' ? $base : $base.'/'.$path;
    }
}
