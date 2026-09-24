<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressAuditService;
use Illuminate\Console\Command;

final class AuditWordPressMigrationCommand extends Command
{
    protected $signature = 'migration:wordpress:audit
                            {--json= : Optional path to write JSON report}
                            {--csv= : Optional path to write CSV report}';

    protected $description = 'Audit WordPress → Laravel import maps (duplicates, orphans, counts).';

    public function handle(WordPressAuditService $audit): int
    {
        $report = $audit->audit();

        $this->info('WordPress migration audit');
        $this->table(['Entity', 'Mapped'], collect($report['map_counts'])->map(fn ($c, $k) => [$k, $c])->values()->all());
        $this->table(['Local table', 'Count'], collect($report['local_counts'])->map(fn ($c, $k) => [$k, $c])->values()->all());
        $this->line('Duplicate emails in maps: '.count($report['duplicate_emails']));
        $this->line('Orphan enrollments: '.$report['orphan_enrollment_count']);

        if ($path = $this->option('json')) {
            $audit->writeJson((string) $path, $report);
            $this->info('Wrote JSON: '.$path);
        }
        if ($path = $this->option('csv')) {
            $audit->writeCsv((string) $path, $report);
            $this->info('Wrote CSV: '.$path);
        }

        return self::SUCCESS;
    }
}
