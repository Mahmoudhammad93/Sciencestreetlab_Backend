<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Migration\Application\Services\WordPress\WordPressConnectionService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * READ-ONLY lookup of historical WordPress user_pass by legacy user ID.
 *
 * Never writes to the historical connection.
 */
class LegacyWordPressCredentialReader
{
    public function __construct(
        private readonly WordPressConnectionService $wordpress,
    ) {}

    /**
     * @return array{ok: bool, user_pass: string|null, code: string|null}
     */
    public function fetchUserPass(string $legacyUserId): array
    {
        $legacyUserId = trim($legacyUserId);
        if ($legacyUserId === '' || ! ctype_digit($legacyUserId)) {
            return ['ok' => false, 'user_pass' => null, 'code' => 'INVALID_LEGACY_ID'];
        }

        if (! $this->wordpress->isConfigured()) {
            return ['ok' => false, 'user_pass' => null, 'code' => 'HISTORICAL_DB_UNAVAILABLE'];
        }

        try {
            $row = DB::connection($this->wordpress->connectionName())
                ->table($this->wordpress->table('users'))
                ->where('ID', (int) $legacyUserId)
                ->first(['ID', 'user_pass']);
        } catch (Throwable) {
            return ['ok' => false, 'user_pass' => null, 'code' => 'HISTORICAL_DB_UNAVAILABLE'];
        }

        if ($row === null) {
            return ['ok' => false, 'user_pass' => null, 'code' => 'LEGACY_USER_MISSING'];
        }

        $pass = (string) ($row->user_pass ?? '');
        if ($pass === '') {
            return ['ok' => false, 'user_pass' => null, 'code' => 'LEGACY_HASH_EMPTY'];
        }

        return ['ok' => true, 'user_pass' => $pass, 'code' => null];
    }
}
