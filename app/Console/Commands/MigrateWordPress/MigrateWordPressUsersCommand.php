<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\WordPressUserImporter;
use Illuminate\Console\Command;

final class MigrateWordPressUsersCommand extends Command
{
    protected $signature = 'migration:wordpress:users {--dry-run : Report without modifying production data}';

    protected $description = 'Import WordPress users. Passwords are NEVER copied — imported users must reset (password_strategy=reset_required).';

    public function handle(WordPressUserImporter $importer): int
    {
        $result = $importer->import((bool) $this->option('dry-run'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ($result['status'] ?? '') === 'blocked' ? self::SUCCESS : self::SUCCESS;
    }
}
