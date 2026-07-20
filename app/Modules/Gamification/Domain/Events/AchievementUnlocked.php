<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Domain\Events;

use App\Modules\Gamification\Infrastructure\Persistence\Models\Achievement;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AchievementUnlocked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Achievement $achievement,
    ) {}
}
