<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Option A: verify historical WordPress password (read-only) then upgrade to Laravel hash.
 */
final class LegacyWordPressPasswordUpgradeService
{
    public function __construct(
        private readonly LegacyWordPressAuthEligibility $eligibility,
        private readonly LegacyWordPressCredentialReader $credentials,
        private readonly WordPressCompatiblePasswordVerifier $verifier,
    ) {}

    /**
     * Attempt legacy verify + atomic Laravel password upgrade.
     *
     * @return array{ok: bool, user: User|null, code: string|null}
     */
    public function attemptUpgrade(User $user, string $plaintextPassword): array
    {
        if (! filter_var(config('wordpress.legacy_auth.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return ['ok' => false, 'user' => null, 'code' => 'DISABLED'];
        }

        $map = $this->eligibility->findEligibleMap($user);
        if ($map === null) {
            // Concurrent upgrade may have finished; accept if Laravel hash now matches.
            $fresh = $user->fresh();
            if ($fresh !== null && Hash::check($plaintextPassword, $fresh->password)) {
                return ['ok' => true, 'user' => $fresh, 'code' => null];
            }

            return ['ok' => false, 'user' => null, 'code' => 'NOT_ELIGIBLE'];
        }

        $legacyId = trim((string) $map->legacy_id);
        $fetched = $this->credentials->fetchUserPass($legacyId);
        if (! $fetched['ok'] || $fetched['user_pass'] === null) {
            $this->safeLog('legacy_auth_unavailable', $user->id, $fetched['code'] ?? 'UNAVAILABLE');

            return ['ok' => false, 'user' => null, 'code' => $fetched['code'] ?? 'UNAVAILABLE'];
        }

        $hash = $fetched['user_pass'];
        $fetched = null; // drop reference early

        if (! $this->verifier->verify($plaintextPassword, $hash)) {
            $this->safeLog('legacy_auth_failure', $user->id, 'VERIFY_FAILED');

            return ['ok' => false, 'user' => null, 'code' => 'VERIFY_FAILED'];
        }

        // Clear hash from local scope before mutating destination.
        unset($hash);

        try {
            $upgraded = DB::transaction(function () use ($user, $plaintextPassword) {
                /** @var User $locked */
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
                if ($locked === null) {
                    return null;
                }

                $eligibleMap = $this->eligibility->findEligibleMap($locked, forUpdate: true);
                if ($eligibleMap === null) {
                    // Concurrent request already upgraded/revoked — succeed only if
                    // the Laravel password now matches the submitted credential.
                    return Hash::check($plaintextPassword, $locked->password) ? $locked->fresh() : null;
                }

                $locked->forceFill([
                    'password' => Hash::make($plaintextPassword),
                ])->save();

                $this->eligibility->revoke(
                    $eligibleMap,
                    LegacyWordPressAuthEligibility::STRATEGY_UPGRADED,
                    'password_upgraded_at',
                );

                return $locked->fresh();
            });
        } catch (Throwable) {
            $this->safeLog('legacy_auth_unavailable', $user->id, 'UPGRADE_TRANSACTION_FAILED');

            return ['ok' => false, 'user' => null, 'code' => 'UPGRADE_FAILED'];
        }

        if ($upgraded === null) {
            return ['ok' => false, 'user' => null, 'code' => 'UPGRADE_FAILED'];
        }

        // Confirm upgraded password matches (guards concurrent/half-state).
        if (! Hash::check($plaintextPassword, $upgraded->password)) {
            $this->safeLog('legacy_auth_unavailable', $user->id, 'POST_UPGRADE_MISMATCH');

            return ['ok' => false, 'user' => null, 'code' => 'UPGRADE_FAILED'];
        }

        $this->safeLog('legacy_auth_success', $user->id, 'UPGRADED');

        return ['ok' => true, 'user' => $upgraded, 'code' => null];
    }

    public function revokeAfterPasswordReset(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->eligibility->revokeForUser(
                $user,
                LegacyWordPressAuthEligibility::STRATEGY_RESET,
                'password_reset_at',
            );
        });
    }

    public function revokeAfterPasswordChange(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->eligibility->revokeForUser(
                $user,
                LegacyWordPressAuthEligibility::STRATEGY_CHANGED,
                'password_changed_at',
            );
        });
    }

    private function safeLog(string $event, ?int $userId, string $code): void
    {
        Log::info($event, [
            'user_id' => $userId,
            'code' => $code,
            'migration_run_id' => $this->eligibility->migrationRunId(),
        ]);
    }
}
