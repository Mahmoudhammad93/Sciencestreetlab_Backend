<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * WordPress legacy connection + readiness probes.
 *
 * Production Multisite site (blog_id=1 / sciencestreetlab.com) uses prefix wp_.
 * Never query wp_2_* or wp_3_* for production imports.
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
     * Logical table suffix for the wordpress connection (e.g. users → wp_users via connection prefix).
     * Do NOT pre-apply WORDPRESS_DB_PREFIX here — Laravel's connection prefix already does.
     */
    public function table(string $suffix): string
    {
        return ltrim($suffix, '_');
    }

    /**
     * Fully qualified physical table name for rare raw SQL (includes configured prefix).
     */
    public function physicalTable(string $suffix): string
    {
        return $this->tablePrefix().ltrim($suffix, '_');
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
            'wc_orders' => null,
            'woocommerce_order_items' => null,
            'learndash_user_activity' => null,
            'learndash_pro_quiz_master' => null,
            'aq_competitions' => null,
            'lpc_competitions' => null,
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

        foreach (array_keys($probes) as $table) {
            try {
                $probes[$table] = Schema::connection($this->connectionName())->hasTable($table);
            } catch (Throwable) {
                $probes[$table] = false;
            }
        }

        $coreReady = ($probes['users'] ?? false) === true && ($probes['posts'] ?? false) === true;

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

        return [
            'ok' => true,
            'reason' => null,
            'inspect' => $inspect,
        ];
    }

    /**
     * Guard: production imports must use wp_ only (never wp_2_ / wp_3_).
     */
    public function assertProductionPrefix(): void
    {
        $prefix = $this->tablePrefix();
        if ($prefix !== 'wp_') {
            throw new \RuntimeException(
                "Refusing WordPress import with prefix [{$prefix}]. Production Multisite site 1 requires WORDPRESS_DB_PREFIX=wp_."
            );
        }
    }
}
