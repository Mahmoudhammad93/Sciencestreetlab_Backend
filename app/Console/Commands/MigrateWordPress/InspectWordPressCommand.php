<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressConnectionService;
use Illuminate\Console\Command;

final class InspectWordPressCommand extends Command
{
    protected $signature = 'migration:wordpress:inspect';

    protected $description = 'Inspect WordPress DB connection readiness (does not invent LearnDash/Woo mappings).';

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

        $this->line('Core probes:');
        foreach ($report['probes'] as $table => $exists) {
            $state = $exists === null ? 'n/a' : ($exists ? 'present' : 'missing');
            $this->line("  - {$report['prefix']}{$table}: {$state}");
        }

        if (($report['blocked_reason'] ?? null) !== null) {
            $this->warn($report['blocked_reason']);
            $this->warn('LearnDash / WooCommerce source SQL remains blocked until a verified dump is supplied.');
        }

        return self::SUCCESS;
    }
}
