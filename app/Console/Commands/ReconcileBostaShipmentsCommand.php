<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Commerce\Application\Services\BostaShipmentReconciliationService;
use Illuminate\Console\Command;

final class ReconcileBostaShipmentsCommand extends Command
{
    protected $signature = 'bosta:reconcile-shipments {--limit= : Max shipments to process this run}';

    protected $description = 'Reconcile non-terminal Bosta shipments against authoritative Bosta API status';

    public function handle(BostaShipmentReconciliationService $reconciliation): int
    {
        if (! (bool) config('bosta.enabled')) {
            $this->warn('Bosta shipping is disabled; skipping reconciliation.');

            return self::SUCCESS;
        }

        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;

        $stats = $reconciliation->reconcileEligible($limit);

        $this->info(sprintf(
            'Bosta reconcile: checked=%d updated=%d fulfilled=%d unchanged=%d failed=%d',
            $stats['checked'],
            $stats['updated'],
            $stats['fulfilled'],
            $stats['unchanged'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
