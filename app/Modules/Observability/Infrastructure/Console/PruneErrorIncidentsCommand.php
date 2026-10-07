<?php

declare(strict_types=1);

namespace App\Modules\Observability\Infrastructure\Console;

use App\Modules\Observability\Infrastructure\Persistence\Models\ErrorIncident;
use Illuminate\Console\Command;

final class PruneErrorIncidentsCommand extends Command
{
    protected $signature = 'observability:prune-error-incidents {--days= : Override retention days}';

    protected $description = 'Delete resolved error incidents older than the configured retention period.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('error_monitoring.retention_days', 90));
        if ($days < 1) {
            $this->error('Retention days must be >= 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = ErrorIncident::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} resolved error incident(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
