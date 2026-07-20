<?php

declare(strict_types=1);

/**
 * Generates module service provider boilerplate.
 * Run: php scripts/scaffold-module-providers.php
 */

$modules = [
    'Identity', 'Catalog', 'Commerce', 'Learning', 'Assessment',
    'Certification', 'Gamification', 'Competition', 'Content',
    'Notification', 'Media', 'Search',
];

$template = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\{MODULE}\Infrastructure\Providers;

use App\Shared\Kernel\ModuleServiceProvider;

final class {MODULE}ServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return '{MODULE}';
    }

    public function register(): void
    {
        //
    }
}

PHP;

foreach ($modules as $module) {
    $content = str_replace('{MODULE}', $module, $template);
    $path = __DIR__."/../app/Modules/{$module}/Infrastructure/Providers/{$module}ServiceProvider.php";
    if (! file_exists($path)) {
        file_put_contents($path, $content);
        echo "Created {$module}ServiceProvider\n";
    }
}

echo "Done.\n";
