<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\AttemptStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizOfficialScore;
use App\Modules\Learning\Application\Services\CoursePlanEntitlementSyncService;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a demo course plan + test student with quiz attempts for manual QA.
 *
 * Run: php artisan db:seed --class=CoursePlanDemoSeeder
 */
final class CoursePlanDemoSeeder extends Seeder
{
    public function run(): void
    {
        $course = Course::query()->where('slug', 'basic-physics-lab')->firstOrFail();
        $forcesLesson = $course->lessons()->where('slug', 'forces')->firstOrFail();
        $lightLesson = $course->lessons()->where('slug', 'light-reflection')->firstOrFail();
        $forcesQuiz = Quiz::query()->where('quizable_id', $forcesLesson->id)->whereHas('questions')->firstOrFail();

        $plan = CoursePlan::query()->updateOrCreate(
            ['course_id' => $course->id, 'name->en' => 'Physics Demo Plan'],
            [
                'name' => ['ar' => 'خطة فيزياء تجريبية', 'en' => 'Physics Demo Plan'],
                'description' => ['ar' => 'للاختبار', 'en' => 'For QA testing of plans and official quiz scores'],
                'price' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'is_lifetime' => true,
                'duration_days' => null,
                'max_quiz_attempts' => 3,
                'grant_certificate' => false,
                'sort_order' => 0,
            ],
        );

        app(CoursePlanEntitlementSyncService::class)->syncPlanEntitlements($plan, [
            'lesson_ids' => [$forcesLesson->id],
            'topic_ids' => [],
            'quiz_ids' => [$forcesQuiz->id],
            'interactive_activity_ids' => [],
        ]);

        $user = User::query()->updateOrCreate(
            ['email' => 'plan-demo@sciencestreetlab.com'],
            [
                'name' => 'Plan Demo Student',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->delete();

        $enrollment = app(EnrollUserService::class)->enroll($user, $course, null, $plan);

        // Clear prior attempts for this quiz
        $forcesQuiz->attempts()->where('user_id', $user->id)->delete();
        QuizOfficialScore::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('quiz_id', $forcesQuiz->id)
            ->delete();

        $service = app(QuizAttemptService::class);
        $questions = $forcesQuiz->questions()->with('options')->orderBy('sort_order')->get();

        // Attempt 1: started then abandoned (must NOT become official)
        $attempt1 = $service->start($user, $forcesQuiz, $enrollment);
        $attempt1->update(['status' => AttemptStatus::Abandoned]);

        // Attempt 2: first submitted — mostly wrong answers (becomes official)
        $attempt2 = $service->start($user, $forcesQuiz, $enrollment);
        $wrongAnswers = $questions->map(function ($question) {
            $wrong = QuestionOption::query()
                ->where('question_id', $question->id)
                ->where('is_correct', false)
                ->first()
                ?? QuestionOption::query()->where('question_id', $question->id)->first();

            return [
                'question_id' => $question->id,
                'selected_option_ids' => [$wrong->id],
            ];
        })->all();
        $service->submit($attempt2, $wrongAnswers);

        // Attempt 3: retry with all correct (must NOT replace official)
        $attempt3 = $service->start($user, $forcesQuiz, $enrollment);
        $correctAnswers = $questions->map(function ($question) {
            $correct = QuestionOption::query()
                ->where('question_id', $question->id)
                ->where('is_correct', true)
                ->firstOrFail();

            return [
                'question_id' => $question->id,
                'selected_option_ids' => [$correct->id],
            ];
        })->all();
        $service->submit($attempt3, $correctAnswers);

        $attempt2->refresh();
        $attempt3->refresh();

        $this->command?->info('');
        $this->command?->info('=== Course Plan Demo Ready ===');
        $this->command?->info("Course: {$course->slug} (id {$course->id})");
        $this->command?->info("Plan: Physics Demo Plan (id {$plan->id}) — forces lesson + quiz only; light lesson excluded");
        $this->command?->info('Student: plan-demo@sciencestreetlab.com / password');
        $this->command?->info("Enrollment id: {$enrollment->id}");
        $this->command?->info("Quiz: #{$forcesQuiz->id} {$forcesQuiz->getTranslation('title', 'en')}");
        $this->command?->info("Attempt 2 (official): score {$attempt2->percentage}% is_official=".($attempt2->is_official ? 'yes' : 'no'));
        $this->command?->info("Attempt 3 (retry):   score {$attempt3->percentage}% is_official=".($attempt3->is_official ? 'yes' : 'no'));
        $this->command?->info('');
        $this->command?->info('Admin: Learning → Courses → basic-physics-lab → Course Plans tab');
        $this->command?->info('API: POST /api/v1/login then GET /api/v1/quiz-attempts/{id}/result');
    }
}
