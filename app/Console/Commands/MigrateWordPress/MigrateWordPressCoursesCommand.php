<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressCourseImporter;
use Illuminate\Console\Command;

final class MigrateWordPressCoursesCommand extends Command
{
    protected $signature = 'migration:wordpress:courses {--dry-run : Report without modifying production data}';

    protected $description = 'Import WordPress/LearnDash courses (blocked until dump schema is supplied).';

    public function handle(WordPressCourseImporter $importer): int
    {
        $result = $importer->import((bool) $this->option('dry-run'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
