<?php

declare(strict_types=1);

namespace App\Modules\Mobile\Application\Services;

use App\Models\User;
use App\Modules\Catalog\Domain\Repositories\ProductRepositoryInterface;
use App\Modules\Competition\Application\Services\CompetitionEligibilityService;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Gamification\Application\Services\PointsService;
use App\Modules\Gamification\Infrastructure\Persistence\Models\UserAchievement;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Support\Facades\Schema;

final class MobileHomeService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly PointsService $points,
        private readonly CompetitionEligibilityService $eligibility,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        $points = $this->points->forUser($user);
        $continueLearning = $this->continueLearning($user);
        $competition = $this->competitionBlock($user);

        $recentAchievements = UserAchievement::query()
            ->where('user_id', $user->id)
            ->with('achievement')
            ->latest('awarded_at')
            ->limit(3)
            ->get()
            ->map(fn ($ua) => [
                'slug' => $ua->achievement->slug,
                'name' => $ua->achievement->getTranslation('name', $user->locale ?? 'ar'),
                'points' => $ua->achievement->points,
                'awarded_at' => $ua->awarded_at->toIso8601String(),
            ]);

        return [
            'user' => [
                'name' => $user->name,
                'points' => $points->total_points,
                'avatar_url' => $user->avatar_path,
                'locale' => $user->locale,
            ],
            'continue_learning' => $continueLearning,
            'competition' => $competition,
            'featured_products' => $this->products->findPublished()->take(6)->values()->all(),
            'recent_achievements' => $recentAchievements,
            'unread_notifications' => Schema::hasTable('notifications')
                ? $user->unreadNotifications()->count()
                : 0,
        ];
    }

    /** @return array<string, mixed>|null */
    private function continueLearning(User $user): ?array
    {
        $enrollment = Enrollment::query()
            ->where('user_id', $user->id)
            ->whereNot('status', EnrollmentStatus::Completed)
            ->orderByDesc('progress_percent')
            ->with(['course.lessons.topics'])
            ->first();

        if (! $enrollment) {
            return null;
        }

        $locale = $user->locale ?? 'ar';
        $nextTopic = null;
        $nextLessonTitle = null;

        foreach ($enrollment->course->lessons as $lesson) {
            foreach ($lesson->topics as $topic) {
                $completion = $enrollment->topicCompletions()
                    ->where('topic_id', $topic->id)
                    ->first();

                if (! $completion || (float) $completion->watch_progress_percent < 90) {
                    $nextTopic = $topic;
                    $nextLessonTitle = $lesson->getTranslation('title', $locale);
                    break 2;
                }
            }
        }

        return [
            'course_slug' => $enrollment->course->slug,
            'course_title' => $enrollment->course->getTranslation('title', $locale),
            'lesson_title' => $nextLessonTitle,
            'next_topic_id' => $nextTopic?->id,
            'next_topic_title' => $nextTopic?->getTranslation('title', $locale),
            'progress_percent' => (float) $enrollment->progress_percent,
        ];
    }

    /** @return array<string, mixed>|null */
    private function competitionBlock(User $user): ?array
    {
        $competition = Competition::query()
            ->where('slug', 'microscope-100-challenge')
            ->whereIn('status', ['active', 'judging'])
            ->first();

        if (! $competition) {
            return null;
        }

        $participant = $competition->participants()->where('user_id', $user->id)->first();
        $eligibility = $this->eligibility->canParticipate($user, $competition);

        return [
            'slug' => $competition->slug,
            'title' => $competition->getTranslation('title', $user->locale ?? 'ar'),
            'eligible' => $eligibility['eligible'],
            'registered' => $participant !== null,
            'approved_photos' => $participant?->approved_count ?? 0,
            'required_photos' => $competition->required_photos,
            'days_remaining' => max(0, now()->diffInDays($competition->ends_at, false)),
        ];
    }
}
