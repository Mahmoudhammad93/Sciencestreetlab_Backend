<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Models\User;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Support\PublicMediaUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ranks learners by official quiz performance for a course.
 *
 * Aggregation: average of official attempt percentages across course lesson quizzes.
 * Only the first successfully submitted attempt counts (via quiz_official_scores).
 * Tie-breakers: score DESC, progress_percent DESC, earliest official submit ASC, user_id ASC.
 */
final class CourseLeaderboardService
{
    /**
     * @return array{
     *   course_id: int,
     *   leaderboard: list<array{
     *     rank: int,
     *     user: array{id: int, name: string, avatar_url: string|null},
     *     score: float,
     *     completion_percentage: float,
     *     is_current_user: bool
     *   }>,
     *   current_user: array{rank: int, score: float, completion_percentage: float, is_current_user: true}|null,
     *   meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function leaderboard(Course $course, ?User $viewer, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);

        $ranked = $this->rankedEntries($course, $viewer);
        $total = $ranked->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $slice = $ranked->slice(($page - 1) * $perPage, $perPage)->values();

        $currentUser = null;
        if ($viewer !== null) {
            $mine = $ranked->first(fn (array $entry): bool => $entry['user']['id'] === $viewer->id);
            if ($mine !== null) {
                $currentUser = [
                    'rank' => $mine['rank'],
                    'score' => $mine['score'],
                    'completion_percentage' => $mine['completion_percentage'],
                    'is_current_user' => true,
                ];
            }
        }

        return [
            'course_id' => $course->id,
            'leaderboard' => $slice->all(),
            'current_user' => $currentUser,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    /**
     * @return Collection<int, array{
     *   rank: int,
     *   user: array{id: int, name: string, avatar_url: string|null},
     *   score: float,
     *   completion_percentage: float,
     *   is_current_user: bool
     * }>
     */
    private function rankedEntries(Course $course, ?User $viewer): Collection
    {
        $lessonMorph = Lesson::class;

        $scoreSubquery = DB::table('quiz_official_scores as qos')
            ->join('quiz_attempts as qa', 'qa.id', '=', 'qos.quiz_attempt_id')
            ->join('quizzes as q', 'q.id', '=', 'qos.quiz_id')
            ->join('lessons as l', function ($join) use ($lessonMorph, $course): void {
                $join->on('l.id', '=', 'q.quizable_id')
                    ->where('q.quizable_type', '=', $lessonMorph)
                    ->where('l.course_id', '=', $course->id);
            })
            ->groupBy('qos.enrollment_id')
            ->select([
                'qos.enrollment_id',
                DB::raw('AVG(qa.percentage) as avg_score'),
                DB::raw('MIN(qa.submitted_at) as earliest_official_at'),
            ]);

        $rows = DB::table('enrollments as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->whereNull('u.deleted_at')
            ->leftJoinSub($scoreSubquery, 'scores', 'scores.enrollment_id', '=', 'e.id')
            ->where('e.course_id', $course->id)
            ->whereIn('e.status', [
                EnrollmentStatus::Active->value,
                EnrollmentStatus::Completed->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('e.expires_at')
                    ->orWhere('e.expires_at', '>', now());
            })
            ->orderByDesc(DB::raw('COALESCE(scores.avg_score, 0)'))
            ->orderByDesc('e.progress_percent')
            ->orderByRaw('CASE WHEN scores.earliest_official_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scores.earliest_official_at')
            ->orderBy('e.user_id')
            ->get([
                'e.user_id',
                'u.name',
                'u.avatar_path',
                'e.progress_percent',
                DB::raw('COALESCE(scores.avg_score, 0) as score'),
            ]);

        $rank = 0;

        return $rows->map(function (object $row) use ($viewer, &$rank): array {
            $rank++;

            return [
                'rank' => $rank,
                'user' => [
                    'id' => (int) $row->user_id,
                    'name' => (string) $row->name,
                    'avatar_url' => PublicMediaUrl::make(
                        $row->avatar_path !== null ? (string) $row->avatar_path : null
                    ),
                ],
                'score' => round((float) $row->score, 2),
                'completion_percentage' => round((float) $row->progress_percent, 2),
                'is_current_user' => $viewer !== null && (int) $row->user_id === $viewer->id,
            ];
        });
    }
}
