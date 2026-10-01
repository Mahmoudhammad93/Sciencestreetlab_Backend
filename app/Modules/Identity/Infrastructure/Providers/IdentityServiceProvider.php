<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Providers;

use App\Modules\Identity\Application\Services\LegacyWordPressAuthEligibility;
use App\Modules\Identity\Application\Services\LegacyWordPressCredentialReader;
use App\Modules\Identity\Application\Services\LegacyWordPressPasswordUpgradeService;
use App\Modules\Identity\Application\Services\WordPressCompatiblePasswordVerifier;
use App\Shared\Kernel\ModuleServiceProvider;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Identity';
    }

    public function register(): void
    {
        $this->app->singleton(WordPressCompatiblePasswordVerifier::class);
        $this->app->singleton(
            LegacyWordPressAuthEligibility::class,
            fn () => LegacyWordPressAuthEligibility::fromConfig(),
        );
        $this->app->singleton(LegacyWordPressCredentialReader::class);
        $this->app->singleton(LegacyWordPressPasswordUpgradeService::class);
    }
}
