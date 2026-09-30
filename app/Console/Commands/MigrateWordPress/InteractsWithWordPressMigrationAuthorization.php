<?php

declare(strict_types=1);

namespace App\Console\Commands\MigrateWordPress;

use App\Modules\Migration\Application\Services\WordPress\ActiveMigrationRun;
use App\Modules\Migration\Application\Services\WordPress\WordPressRealPersistGate;
use Illuminate\Console\Command;

/**
 * Shared helpers for mutating WordPress migration Artisan commands.
 */
trait InteractsWithWordPressMigrationAuthorization
{
    protected function migrationRunOption(): ?int
    {
        $raw = $this->option('migration-run');
        if ($raw === null || $raw === '') {
            return null;
        }

        return is_numeric($raw) ? (int) $raw : null;
    }

    /**
     * @return array{ok: true}|array{ok: false, code: int}
     */
    protected function beginMutatingImport(WordPressRealPersistGate $gate, string $entityType): array
    {
        $auth = $gate->authorizeMutation($this->migrationRunOption(), $entityType);
        if (! $auth['ok']) {
            $this->line(json_encode($auth['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->error((string) ($auth['result']['code'] ?? WordPressRealPersistGate::REAL_PERSIST_NOT_AUTHORIZED));

            return ['ok' => false, 'code' => self::FAILURE];
        }

        return ['ok' => true];
    }

    protected function endMutatingImport(): void
    {
        ActiveMigrationRun::clear();
    }
}
