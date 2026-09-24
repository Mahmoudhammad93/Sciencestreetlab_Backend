<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Builds and probes the optional `wordpress` DB connection.
 *
 * LearnDash / WooCommerce table+column mappings remain
 * BLOCKED_UNTIL_WORDPRESS_DB_DUMP — only WordPress core probes are allowed.
 */
final class WordPressConnectionService
{
    public const BLOCKED = 'BLOCKED_UNTIL_WORDPRESS_DB_DUMP';

    public function connectionName(): string
    {
        return (string) config('wordpress.connection', 'wordpress');
    }

    public function tablePrefix(): string
    {
        return (string) config('wordpress.prefix', 'wp_');
    }

    /**
     * @return list<string>
     */
    public function missingConfigKeys(): array
    {
        $missing = [];

        foreach (['host', 'database', 'username'] as $key) {
            $value = config("wordpress.{$key}");
            if ($value === null || $value === '') {
                $missing[] = 'WORDPRESS_DB_'.strtoupper($key === 'database' ? 'DATABASE' : $key);
            }
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missingConfigKeys() === [];
    }

    /**
     * @return array{
     *     configured: bool,
     *     missing: list<string>,
     *     connection: string,
     *     prefix: string,
     *     reachable: bool|null,
     *     error: string|null,
     *     probes: array<string, bool|null>,
     *     status: string,
     *     blocked_reason: string|null
     * }
     */
    public function inspect(): array
    {
        $missing = $this->missingConfigKeys();
        $probes = [
            'users' => null,
            'posts' => null,
        ];

        if ($missing !== []) {
            return [
                'configured' => false,
                'missing' => $missing,
                'connection' => $this->connectionName(),
                'prefix' => $this->tablePrefix(),
                'reachable' => null,
                'error' => null,
                'probes' => $probes,
                'status' => 'blocked',
                'blocked_reason' => self::BLOCKED,
            ];
        }

        try {
            DB::connection($this->connectionName())->getPdo();
        } catch (Throwable $e) {
            return [
                'configured' => true,
                'missing' => [],
                'connection' => $this->connectionName(),
                'prefix' => $this->tablePrefix(),
                'reachable' => false,
                'error' => $e->getMessage(),
                'probes' => $probes,
                'status' => 'blocked',
                'blocked_reason' => self::BLOCKED,
            ];
        }

        // Connection prefix is applied by Schema; probe well-known core table names only.
        foreach (array_keys($probes) as $table) {
            try {
                $probes[$table] = Schema::connection($this->connectionName())->hasTable($table);
            } catch (Throwable) {
                $probes[$table] = false;
            }
        }

        $coreReady = ($probes['users'] ?? false) === true;

        return [
            'configured' => true,
            'missing' => [],
            'connection' => $this->connectionName(),
            'prefix' => $this->tablePrefix(),
            'reachable' => true,
            'error' => null,
            'probes' => $probes,
            'status' => $coreReady ? 'connected' : 'blocked',
            'blocked_reason' => $coreReady ? null : self::BLOCKED,
        ];
    }

    /**
     * Whether source-specific import SQL may run.
     * Always false until a verified WordPress dump unlocks LearnDash/Woo mappings.
     *
     * @return array{ok: bool, reason: string|null, inspect: array<string, mixed>}
     */
    public function assertReadyForImport(string $expectedCoreTable = 'users'): array
    {
        $inspect = $this->inspect();

        if (! $inspect['configured'] || $inspect['reachable'] !== true) {
            return [
                'ok' => false,
                'reason' => self::BLOCKED,
                'inspect' => $inspect,
            ];
        }

        $probe = $inspect['probes'][$expectedCoreTable] ?? false;
        if ($probe !== true) {
            return [
                'ok' => false,
                'reason' => self::BLOCKED,
                'inspect' => $inspect,
            ];
        }

        // Core probe passed, but LearnDash / WooCommerce mappings are still blocked.
        return [
            'ok' => false,
            'reason' => self::BLOCKED,
            'inspect' => $inspect,
        ];
    }
}
