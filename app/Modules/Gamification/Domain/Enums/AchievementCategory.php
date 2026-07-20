<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Domain\Enums;

enum AchievementCategory: string
{
    case Course = 'course';
    case Quiz = 'quiz';
    case Competition = 'competition';
    case Engagement = 'engagement';
    case Milestone = 'milestone';
}
