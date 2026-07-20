<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Providers;

use App\Modules\Catalog\Domain\Repositories\ProductRepositoryInterface;
use App\Modules\Catalog\Infrastructure\Persistence\EloquentProductRepository;
use App\Shared\Kernel\ModuleServiceProvider;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Catalog';
    }

    public function register(): void
    {
        $this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);
    }
}
