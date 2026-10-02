<?php

declare(strict_types=1);

namespace App\Modules\Migration\Infrastructure\Providers;

use App\Modules\Migration\Application\Services\WordPress\LegacyImportMapRepository;
use App\Modules\Migration\Application\Services\WordPress\MigrationRunService;
use App\Modules\Migration\Application\Services\WordPress\WordPressApprovedCollisionMapper;
use App\Modules\Migration\Application\Services\WordPress\WordPressAuditService;
use App\Modules\Migration\Application\Services\WordPress\WordPressCollisionAnalyzer;
use App\Modules\Migration\Application\Services\WordPress\WordPressCompetitionImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressCompetitionSlotAnalyzer;
use App\Modules\Migration\Application\Services\WordPress\WordPressConnectionService;
use App\Modules\Migration\Application\Services\WordPress\WordPressCourseImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressCourseTreeAnalyzer;
use App\Modules\Migration\Application\Services\WordPress\WordPressEnrollmentImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressHistoricalQuizAnswerNormalizer;
use App\Modules\Migration\Application\Services\WordPress\WordPressMediaImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressMediaLocalSource;
use App\Modules\Migration\Application\Services\WordPress\WordPressMediaOwnershipResolver;
use App\Modules\Migration\Application\Services\WordPress\WordPressMediaTransferPlanner;
use App\Modules\Migration\Application\Services\WordPress\WordPressOrderImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressProductImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressProQuizAnswerDecoder;
use App\Modules\Migration\Application\Services\WordPress\WordPressQuizAttemptHistoryAnalyzer;
use App\Modules\Migration\Application\Services\WordPress\WordPressQuizAttemptImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressQuizImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressQuizLessonMappingAnalyzer;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use App\Modules\Migration\Application\Services\WordPress\WordPressUserImporter;
use App\Modules\Migration\Application\Services\WordPress\WordPressVariableProductAnalyzer;
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
        $this->app->singleton(WordPressCourseTreeAnalyzer::class);
        $this->app->singleton(WordPressVariableProductAnalyzer::class);
        $this->app->singleton(WordPressCompetitionSlotAnalyzer::class);
        $this->app->singleton(WordPressCollisionAnalyzer::class);
        $this->app->singleton(WordPressApprovedCollisionMapper::class);
        $this->app->singleton(MigrationRunService::class);
        $this->app->singleton(WordPressRealPersistGate::class);
        $this->app->singleton(WordPressUserImporter::class);
        $this->app->singleton(WordPressCourseImporter::class);
        $this->app->singleton(WordPressEnrollmentImporter::class);
        $this->app->singleton(WordPressOrderImporter::class);
        $this->app->singleton(WordPressProductImporter::class);
        $this->app->singleton(WordPressProQuizAnswerDecoder::class);
        $this->app->singleton(WordPressQuizImporter::class);
        $this->app->singleton(WordPressQuizLessonMappingAnalyzer::class);
        $this->app->singleton(WordPressQuizAttemptHistoryAnalyzer::class);
        $this->app->singleton(WordPressHistoricalQuizAnswerNormalizer::class);
        $this->app->singleton(WordPressQuizAttemptImporter::class);
        $this->app->singleton(WordPressCompetitionImporter::class);
        $this->app->singleton(WordPressMediaOwnershipResolver::class);
        $this->app->singleton(WordPressMediaLocalSource::class);
        $this->app->singleton(WordPressMediaTransferPlanner::class);
        $this->app->singleton(WordPressMediaImporter::class);
        $this->app->singleton(WordPressAuditService::class);
    }

    public function boot(): void
    {
        // Migration module has no HTTP routes — only load persistence migrations.
        $this->loadMigrationsFrom($this->modulePath('Infrastructure/Persistence/Migrations'));
    }
}
