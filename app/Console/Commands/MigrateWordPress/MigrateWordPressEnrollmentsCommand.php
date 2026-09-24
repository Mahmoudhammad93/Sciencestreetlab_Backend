<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressEnrollmentImporter;
use Illuminate\Console\Command;

final class MigrateWordPressEnrollmentsCommand extends Command
{
    protected $signature = 'migration:wordpress:enrollments {--dry-run : Report without modifying production data}';

    protected $description = 'Import LearnDash enrollments (blocked until dump schema is supplied).';

    public function handle(WordPressEnrollmentImporter $importer): int
    {
        $result = $importer->import((bool) $this->option('dry-run'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
