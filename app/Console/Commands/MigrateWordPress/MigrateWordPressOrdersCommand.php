<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressOrderImporter;
use Illuminate\Console\Command;

final class MigrateWordPressOrdersCommand extends Command
{
    protected $signature = 'migration:wordpress:orders {--dry-run : Report without modifying production data}';

    protected $description = 'Import historical WooCommerce orders without firing payment/fulfillment events (blocked until dump).';

    public function handle(WordPressOrderImporter $importer): int
    {
        $result = $importer->import((bool) $this->option('dry-run'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
