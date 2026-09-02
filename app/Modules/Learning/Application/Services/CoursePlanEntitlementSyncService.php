<?php

declare(strict_types=1);

namespace App\Modules\Learning\Application\Services;

use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlanEntitlement;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\EnrollmentEntitlement;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use DomainException;
use Illuminate\Support\Collection;

final class CoursePlanEntitlementSyncService
{
    /**
     * @param  array{
     *   lesson_ids?: list<int>,
     *   topic_ids?: list<int>,
     *   quiz_ids?: list<int>,
     *   interactive_activity_ids?: list<int>
     * }  $selection
     */
    public function syncPlanEntitlements(CoursePlan $plan, array $selection): void
    {
        $course = $plan->course()->with(['lessons.topics', 'lessons.quizzes', 'lessons.interactiveActivities'])->firstOrFail();

        $this->assertResourcesBelongToCourse($course, $selection);

        $plan->entitlements()->delete();

        foreach ($this->buildEntitlementRows($selection) as $row) {
            CoursePlanEntitlement::query()->create([
                'course_plan_id' => $plan->id,
                'entitleable_type' => $row['type'],
                'entitleable_id' => $row['id'],
            ]);
        }
    }

    public function snapshotEntitlementsForEnrollment(Enrollment $enrollment, CoursePlan $plan): void
    {
        $plan->loadMissing('entitlements');

        $enrollment->entitlements()->delete();

        foreach ($plan->entitlements as $entitlement) {
            EnrollmentEntitlement::query()->create([
                'enrollment_id' => $enrollment->id,
                'entitleable_type' => $entitlement->entitleable_type,
                'entitleable_id' => $entitlement->entitleable_id,
            ]);
        }

        $enrollment->update(['grant_certificate' => (bool) $plan->grant_certificate]);
    }

    /**
     * @param  array{
     *   lesson_ids?: list<int>,
     *   topic_ids?: list<int>,
     *   quiz_ids?: list<int>,
     *   interactive_activity_ids?: list<int>
     * }  $selection
     * @return list<array{type: class-string, id: int}>
     */
    private function buildEntitlementRows(array $selection): array
    {
        $rows = [];

        foreach ($selection['lesson_ids'] ?? [] as $id) {
            $rows[] = ['type' => Lesson::class, 'id' => (int) $id];
        }
        foreach ($selection['topic_ids'] ?? [] as $id) {
            $rows[] = ['type' => Topic::class, 'id' => (int) $id];
        }
        foreach ($selection['quiz_ids'] ?? [] as $id) {
            $rows[] = ['type' => Quiz::class, 'id' => (int) $id];
        }
        foreach ($selection['interactive_activity_ids'] ?? [] as $id) {
            $rows[] = ['type' => InteractiveActivity::class, 'id' => (int) $id];
        }

        return $rows;
    }

    /**
     * @param  array{
     *   lesson_ids?: list<int>,
     *   topic_ids?: list<int>,
     *   quiz_ids?: list<int>,
     *   interactive_activity_ids?: list<int>
     * }  $selection
     */
    private function assertResourcesBelongToCourse(Course $course, array $selection): void
    {
        $lessonIds = $course->lessons->pluck('id');
        $topicIds = $course->lessons->flatMap->topics->pluck('id');
        $quizIds = $course->lessons->flatMap->quizzes->pluck('id');
        $activityIds = $course->lessons->flatMap->interactiveActivities->pluck('id');

        foreach ($selection['lesson_ids'] ?? [] as $id) {
            if (! $lessonIds->contains((int) $id)) {
                throw new DomainException('Selected lesson does not belong to this course.');
            }
        }
        foreach ($selection['topic_ids'] ?? [] as $id) {
            if (! $topicIds->contains((int) $id)) {
                throw new DomainException('Selected topic does not belong to this course.');
            }
        }
        foreach ($selection['quiz_ids'] ?? [] as $id) {
            if (! $quizIds->contains((int) $id)) {
                throw new DomainException('Selected quiz does not belong to this course.');
            }
        }
        foreach ($selection['interactive_activity_ids'] ?? [] as $id) {
            if (! $activityIds->contains((int) $id)) {
                throw new DomainException('Selected interactive activity does not belong to this course.');
            }
        }
    }

    /**
     * @return array{
     *   lesson_ids: list<int>,
     *   topic_ids: list<int>,
     *   quiz_ids: list<int>,
     *   interactive_activity_ids: list<int>
     * }
     */
    public function selectionFromPlan(CoursePlan $plan): array
    {
        $plan->loadMissing('entitlements');

        return [
            'lesson_ids' => $this->idsForType($plan->entitlements, Lesson::class),
            'topic_ids' => $this->idsForType($plan->entitlements, Topic::class),
            'quiz_ids' => $this->idsForType($plan->entitlements, Quiz::class),
            'interactive_activity_ids' => $this->idsForType($plan->entitlements, InteractiveActivity::class),
        ];
    }

    /**
     * @param  Collection<int, CoursePlanEntitlement>  $entitlements
     * @return list<int>
     */
    private function idsForType(Collection $entitlements, string $type): array
    {
        return $entitlements
            ->where('entitleable_type', $type)
            ->pluck('entitleable_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
