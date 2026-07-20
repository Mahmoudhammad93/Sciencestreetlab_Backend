<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Assessment\Infrastructure\Providers\AssessmentServiceProvider;
use App\Modules\Catalog\Infrastructure\Providers\CatalogServiceProvider;
use App\Modules\Certification\Infrastructure\Providers\CertificationServiceProvider;
use App\Modules\Commerce\Infrastructure\Providers\CommerceServiceProvider;
use App\Modules\Competition\Infrastructure\Providers\CompetitionServiceProvider;
use App\Modules\Content\Infrastructure\Providers\ContentServiceProvider;
use App\Modules\Gamification\Infrastructure\Providers\GamificationServiceProvider;
use App\Modules\Identity\Infrastructure\Providers\IdentityServiceProvider;
use App\Modules\Learning\Infrastructure\Providers\LearningServiceProvider;
use App\Modules\Media\Infrastructure\Providers\MediaServiceProvider;
use App\Modules\Mobile\Infrastructure\Providers\MobileServiceProvider;
use App\Modules\Notification\Infrastructure\Providers\NotificationServiceProvider;
use App\Modules\Search\Infrastructure\Providers\SearchServiceProvider;
use Illuminate\Support\ServiceProvider;

final class ModuleAggregatorServiceProvider extends ServiceProvider
{
    /** @var list<class-string<ServiceProvider>> */
    private array $modules = [
        IdentityServiceProvider::class,
        CatalogServiceProvider::class,
        CommerceServiceProvider::class,
        LearningServiceProvider::class,
        AssessmentServiceProvider::class,
        CertificationServiceProvider::class,
        GamificationServiceProvider::class,
        CompetitionServiceProvider::class,
        ContentServiceProvider::class,
        NotificationServiceProvider::class,
        MediaServiceProvider::class,
        MobileServiceProvider::class,
        SearchServiceProvider::class,
    ];

    public function register(): void
    {
        foreach ($this->modules as $module) {
            $this->app->register($module);
        }
    }
}
