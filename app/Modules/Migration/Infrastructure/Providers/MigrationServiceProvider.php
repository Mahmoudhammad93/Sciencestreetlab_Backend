<?php

declare(strict_types=1);

namespace App\Modules\Migration\Infrastructure\Providers;

use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\WordPressAuditService;
use App\Modules\Migration\Application\Services\WordPress\WordPressConnectionService;
use App\Modules\Migration\Application\Services\WordPress\WordPressCourseImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressEnrollmentImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressOrderImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressUserImporter;
use App\Shared\Kernel\ModuleServiceProvider;

final class MigrationServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Migration';
    }

    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/wordpress.php'), 'wordpress');

        $this->app->singleton(WordPressConnectionService::class);
        $this->app->singleton(LegacyImportMapRepository::class);
        $this->app->singleton(WordPressUserImporter::class);
        $this->app->singleton(WordPressCourseImporter::class);
        $this->app->singleton(WordPressEnrollmentImporter::class);
        $this->app->singleton(WordPressOrderImporter::class);
        $this->app->singleton(WordPressAuditService::class);
    }

    public function boot(): void
    {
        // Migration module has no HTTP routes — only load persistence migrations.
        $this->loadMigrationsFrom($this->modulePath('Infrastructure/Persistence/Migrations'));
    }
}
