<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressConnectionService;
use Illuminate\Console\Command;

final class InspectWordPressCommand extends Command
{
    protected $signature = 'migration:wordpress:inspect';

    protected $description = 'Inspect WordPress legacy DB connection and production (wp_) table probes.';

    public function handle(WordPressConnectionService $connection): int
    {
        $report = $connection->inspect();

        $this->info('WordPress migration inspect');
        $this->table(
            ['Key', 'Value'],
            collect($report)->except('probes')->map(fn ($v, $k) => [
                $k,
                is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? json_encode($v) : (string) ($v ?? '')),
            ])->values()->all()
        );

        $this->line('Core probes (prefix '.$report['prefix'].'):');
        foreach ($report['probes'] as $table => $exists) {
            $state = $exists === null ? 'n/a' : ($exists ? 'present' : 'missing');
            $this->line("  - {$report['prefix']}{$table}: {$state}");
        }

        if (($report['status'] ?? '') === 'connected') {
            $this->info('Connection OK. Production Multisite prefix must remain wp_ (exclude wp_2_ / wp_3_).');

            return self::SUCCESS;
        }

        if (($report['blocked_reason'] ?? null) !== null) {
            $this->warn((string) $report['blocked_reason']);
            $this->warn('Configure WORDPRESS_DB_* to wordpress_legacy and verify dump import before dry-runs.');
        }

        return self::SUCCESS;
    }
}
