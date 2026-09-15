<?php

declare(strict_types=1);

/**
 * Real end-to-end verification against the local running API + DB.
 * Run: php artisan tinker --execute="require base_path('scripts/live_verify_course_plans.php');"
 */

use App\Models\User;
use App\Modules\Assessment\Application\Services\OfficialQuizScoreService;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Catalog\Application\Services\ProductEducationalSpecSyncService;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Learning\Application\Services\CourseAccessService;
use App\Modules\Learning\Application\Services\CoursePlanEntitlementSyncService;
use App\Modules\Learning\Application\Services\EnrollUserService;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$base = rtrim((string) config('app.url'), '/');
$results = [];
$fail = 0;

$assert = function (string $name, bool $ok, string $detail = '') use (&$results, &$fail): void {
    $results[] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
    ];
    if (! $ok) {
        $fail++;
    }
    echo ($ok ? 'PASS' : 'FAIL')."  {$name}".($detail !== '' ? " — {$detail}" : '').PHP_EOL;
};

$tag = Str::lower(Str::random(6));

$course = Course::query()->create([
    'slug' => "live-plan-{$tag}",
    'access_type' => AccessType::Paid,
    'is_published' => true,
    'published_at' => now(),
    'title' => ['en' => 'Live Plan Course', 'ar' => 'دورة لايف'],
]);

$lessons = [];
$topics = [];
for ($i = 1; $i <= 2; $i++) {
    $lesson = Lesson::query()->create([
        'course_id' => $course->id,
        'slug' => "live-l{$i}-{$tag}",
        'lesson_type' => 'theory',
        'sort_order' => $i,
        'is_published' => true,
        'title' => ['en' => "Lesson {$i}", 'ar' => "درس {$i}"],
    ]);
    $lessons[$i] = $lesson;
    $topics[$i] = [];
    for ($t = 1; $t <= 3; $t++) {
        $topics[$i][$t] = Topic::query()->create([
            'lesson_id' => $lesson->id,
            'slug' => "live-t{$i}-{$t}-{$tag}",
            'content_type' => 'text',
            'sort_order' => $t,
            'is_published' => true,
            'title' => ['en' => "Topic {$t}", 'ar' => "موضوع {$t}"],
            'content' => ['en' => "Body {$t}", 'ar' => "محتوى {$t}"],
        ]);
    }
}

$quiz = Quiz::query()->create([
    'quizable_type' => Lesson::class,
    'quizable_id' => $lessons[2]->id,
    'passing_score' => 50,
    'is_required' => false,
    'title' => ['en' => 'Live Quiz', 'ar' => 'اختبار'],
]);

$activity = InteractiveActivity::query()->create([
    'lesson_id' => $lessons[2]->id,
    'title' => ['en' => 'Live Activity', 'ar' => 'نشاط'],
    'status' => InteractiveActivityStatus::Published,
    'entry_file' => 'index.html',
    'version' => 1,
]);

$sync = app(CoursePlanEntitlementSyncService::class);
$access = app(CourseAccessService::class);
$enroll = app(EnrollUserService::class);

$planLesson2 = CoursePlan::query()->create([
    'course_id' => $course->id,
    'name' => ['en' => 'Lesson 2 Only', 'ar' => 'درس 2'],
    'price' => 0,
    'currency' => 'EGP',
    'is_active' => true,
    'is_lifetime' => true,
    'sort_order' => 1,
    'max_quiz_attempts' => 3,
    'grant_certificate' => true,
]);
$sync->syncPlanEntitlements($planLesson2, ['lesson_ids' => [$lessons[2]->id]]);

$user = User::factory()->create([
    'email' => "live.plan.{$tag}@example.com",
    'password' => Hash::make('Password123!'),
]);

$enrollment = $enroll->enroll($user, $course, null, $planLesson2)->load(['entitlements', 'coursePlan', 'course']);

$assert('Plan lesson2 accessible without completing lesson1', $access->canAccessLesson($enrollment, $lessons[2]));
$assert('Plan lesson1 inaccessible', ! $access->canAccessLesson($enrollment, $lessons[1]));
$assert('All lesson2 topics accessible', collect($topics[2])->every(fn ($t) => $access->canAccessTopic($enrollment, $t)));
$assert('Lesson1 topics inaccessible', collect($topics[1])->every(fn ($t) => ! $access->canAccessTopic($enrollment, $t)));

$planTopic = CoursePlan::query()->create([
    'course_id' => $course->id,
    'name' => ['en' => 'Topic Only', 'ar' => 'موضوع'],
    'price' => 0,
    'currency' => 'EGP',
    'is_active' => true,
    'is_lifetime' => true,
    'sort_order' => 2,
]);
$lateTopic = $topics[1][3];
$sync->syncPlanEntitlements($planTopic, ['topic_ids' => [$lateTopic->id]]);
$user2 = User::factory()->create(['email' => "live.topic.{$tag}@example.com"]);
$en2 = $enroll->enroll($user2, $course, null, $planTopic)->load(['entitlements', 'course']);
$assert('Direct topic entitlement without previous topics', $access->canAccessTopic($en2, $lateTopic) && ! $access->canAccessTopic($en2, $topics[1][1]));

$planQuiz = CoursePlan::query()->create([
    'course_id' => $course->id,
    'name' => ['en' => 'Quiz Only', 'ar' => 'اختبار فقط'],
    'price' => 0,
    'currency' => 'EGP',
    'is_active' => true,
    'is_lifetime' => true,
    'sort_order' => 3,
]);
$sync->syncPlanEntitlements($planQuiz, ['quiz_ids' => [$quiz->id]]);
$user3 = User::factory()->create(['email' => "live.quiz.{$tag}@example.com"]);
$en3 = $enroll->enroll($user3, $course, null, $planQuiz)->load(['entitlements', 'course']);
$assert('Direct quiz entitlement', $access->canAccessQuiz($en3, $quiz));
$assert('Quiz-only plan does not unlock lesson1', ! $access->canAccessLesson($en3, $lessons[1]));

$planAct = CoursePlan::query()->create([
    'course_id' => $course->id,
    'name' => ['en' => 'Activity Only', 'ar' => 'نشاط فقط'],
    'price' => 0,
    'currency' => 'EGP',
    'is_active' => true,
    'is_lifetime' => true,
    'sort_order' => 4,
]);
$sync->syncPlanEntitlements($planAct, ['interactive_activity_ids' => [$activity->id]]);
$user4 = User::factory()->create(['email' => "live.act.{$tag}@example.com"]);
$en4 = $enroll->enroll($user4, $course, null, $planAct)->load(['entitlements', 'course']);
$assert('Direct interactive activity entitlement', $access->canAccessInteractiveActivity($en4, $activity));

try {
    $sync->syncPlanEntitlements($planLesson2, ['lesson_ids' => [999999]]);
    $assert('Reject foreign/invalid lesson ids', false, 'expected DomainException');
} catch (DomainException $e) {
    $assert('Reject foreign/invalid lesson ids', true, $e->getMessage());
}

try {
    $empty = CoursePlan::query()->create([
        'course_id' => $course->id,
        'name' => ['en' => 'Empty Active', 'ar' => 'فارغ'],
        'price' => 0,
        'currency' => 'EGP',
        'is_active' => true,
        'is_lifetime' => true,
    ]);
    $sync->syncPlanEntitlements($empty, []);
    $assert('Reject empty active plan', false);
} catch (DomainException $e) {
    $assert('Reject empty active plan', true, $e->getMessage());
}

// Paid plan upgrade path (service-level, preserves single enrollment)
$planA = CoursePlan::query()->create([
    'course_id' => $course->id,
    'name' => ['en' => 'Upgrade A', 'ar' => 'أ'],
    'price' => 50,
    'currency' => 'EGP',
    'is_active' => true,
    'is_lifetime' => true,
]);
$planB = CoursePlan::query()->create([
    'course_id' => $course->id,
    'name' => ['en' => 'Upgrade B', 'ar' => 'ب'],
    'price' => 80,
    'currency' => 'EGP',
    'is_active' => true,
    'is_lifetime' => true,
]);
$sync->syncPlanEntitlements($planA, ['lesson_ids' => [$lessons[1]->id]]);
$sync->syncPlanEntitlements($planB, ['lesson_ids' => [$lessons[2]->id]]);
$upgradeUser = User::factory()->create(['email' => "live.upgrade.{$tag}@example.com"]);
$orderA = Order::query()->create([
    'user_id' => $upgradeUser->id,
    'status' => 'paid',
    'order_type' => 'course',
    'subtotal' => 50,
    'discount_amount' => 0,
    'shipping_amount' => 0,
    'tax_amount' => 0,
    'total' => 50,
    'currency' => 'EGP',
    'billing_address' => [],
    'shipping_address' => [],
]);
$itemA = $orderA->items()->create([
    'product_name' => 'A',
    'product_sku' => 'A',
    'quantity' => 1,
    'unit_price' => 50,
    'total_price' => 50,
    'metadata' => ['course_id' => $course->id, 'course_plan_id' => $planA->id],
]);
$enA = $enroll->enroll($upgradeUser, $course, $itemA->id, $planA);
$orderB = Order::query()->create([
    'user_id' => $upgradeUser->id,
    'status' => 'paid',
    'order_type' => 'course',
    'subtotal' => 80,
    'discount_amount' => 0,
    'shipping_amount' => 0,
    'tax_amount' => 0,
    'total' => 80,
    'currency' => 'EGP',
    'billing_address' => [],
    'shipping_address' => [],
]);
$itemB = $orderB->items()->create([
    'product_name' => 'B',
    'product_sku' => 'B',
    'quantity' => 1,
    'unit_price' => 80,
    'total_price' => 80,
    'metadata' => ['course_id' => $course->id, 'course_plan_id' => $planB->id],
]);
$enB = $enroll->enroll($upgradeUser, $course, $itemB->id, $planB)->load('entitlements');
$assert('Paid second plan upgrades same enrollment', $enA->id === $enB->id && $enB->course_plan_id === $planB->id);
$assert('Upgraded snapshot unlocks lesson2 only', $access->canAccessLesson($enB, $lessons[2]) && ! $access->canAccessLesson($enB, $lessons[1]));

// Product educational specs
$related = Course::query()->where('is_published', true)->where('id', '!=', $course->id)->first() ?? $course;
$product = Product::query()->create([
    'sku' => 'LIVE-EDU-'.$tag,
    'slug' => 'live-edu-'.$tag,
    'type' => ProductType::Bundle,
    'status' => ProductStatus::Published,
    'price' => 199,
    'currency' => 'EGP',
    'published_at' => now(),
    'name' => ['en' => 'Live Edu Bundle', 'ar' => 'باقة'],
    'difficulty_level' => 'Intermediate',
    'target_age' => '10-14',
    'key_benefits' => ['Hands-on', 'Aligned'],
    'scientific_concepts' => ['Cells'],
    'design_lab_description' => 'Design lab copy',
    'creative_lab_description' => 'Creative lab copy',
    'related_course_id' => $related->id,
]);
app(ProductEducationalSpecSyncService::class)->syncCurriculumAlignments($product, [
    ['grade_level' => 'Grade 6', 'lesson_name' => 'Cells'],
]);

$prodHttp = Http::acceptJson()->get("{$base}/api/v1/products/{$product->slug}");
$assert('Product API returns educational specs', $prodHttp->successful()
    && $prodHttp->json('data.difficultyLevel') === 'Intermediate'
    && $prodHttp->json('data.curriculumAlignment.0.grade_level') === 'Grade 6'
    && $prodHttp->json('data.relatedCourse.id') === $related->id,
    'status='.$prodHttp->status()
);

$localeHttp = Http::acceptJson()->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])->get("{$base}/api/v1/products");
$assert('Accept-Language en-US works on API', $localeHttp->successful());

$plansHttp = Http::acceptJson()->get("{$base}/api/v1/courses/{$course->slug}/plans");
$assert('Plans API exposes entitlement ids', $plansHttp->successful()
    && collect($plansHttp->json('data'))->contains(fn ($p) => ($p['id'] ?? null) === $planLesson2->id && in_array($lessons[2]->id, $p['lesson_ids'] ?? [], true)),
    'status='.$plansHttp->status()
);

$token = $user->createToken('live-verify')->plainTextToken;
$accessHttp = Http::acceptJson()
    ->withToken($token)
    ->get("{$base}/api/v1/courses/{$course->slug}/access");
$assert('Authenticated access summary uses plan', $accessHttp->successful()
    && $accessHttp->json('uses_plan') === true
    && (int) $accessHttp->json('plan.id') === $planLesson2->id
    && in_array($lessons[2]->id, $accessHttp->json('access.lesson_ids') ?? [], true),
    json_encode($accessHttp->json())
);

$lessonHttp = Http::acceptJson()
    ->withToken($token)
    ->get("{$base}/api/v1/lessons/{$lessons[2]->id}");
$assert('Lesson2 API unlocked for plan user', $lessonHttp->successful()
    && collect($lessonHttp->json('data.topics') ?? [])->every(fn ($t) => ($t['is_locked'] ?? true) === false),
    'status='.$lessonHttp->status()
);

$lesson1Http = Http::acceptJson()
    ->withToken($token)
    ->get("{$base}/api/v1/lessons/{$lessons[1]->id}");
$assert(
    'Lesson1 API denied or locked under lesson2 plan',
    in_array($lesson1Http->status(), [403, 422], true)
        || collect($lesson1Http->json('data.topics') ?? [])->contains(fn ($t) => ($t['is_locked'] ?? false) === true),
    'status='.$lesson1Http->status().' body='.substr((string) $lesson1Http->body(), 0, 220)
);

$outlineHttp = Http::acceptJson()
    ->withToken($token)
    ->get("{$base}/api/v1/courses/{$course->slug}/lessons");
$assert(
    'Course outline locks lesson1 and unlocks lesson2',
    $outlineHttp->successful()
        && collect($outlineHttp->json('data') ?? [])->firstWhere('id', $lessons[1]->id)['is_locked'] === true
        && collect($outlineHttp->json('data') ?? [])->firstWhere('id', $lessons[2]->id)['is_locked'] === false,
    'status='.$outlineHttp->status()
);

// Official quiz score with self-contained fixtures
$officialQuiz = Quiz::query()->create([
    'quizable_type' => Lesson::class,
    'quizable_id' => $lessons[2]->id,
    'passing_score' => 50,
    'max_attempts' => 5,
    'is_required' => false,
    'title' => ['en' => 'Official Live Quiz', 'ar' => 'رسمي'],
]);
$question = Question::query()->create([
    'quiz_id' => $officialQuiz->id,
    'question_type' => QuestionType::SingleChoice,
    'points' => 1,
    'sort_order' => 1,
    'prompt' => ['en' => '2+2?', 'ar' => '2+2؟'],
]);
$wrong = QuestionOption::query()->create([
    'question_id' => $question->id,
    'is_correct' => false,
    'sort_order' => 1,
    'text' => ['en' => '3', 'ar' => '3'],
]);
$correct = QuestionOption::query()->create([
    'question_id' => $question->id,
    'is_correct' => true,
    'sort_order' => 2,
    'text' => ['en' => '4', 'ar' => '4'],
]);
$officialPlan = CoursePlan::query()->create([
    'course_id' => $course->id,
    'name' => ['en' => 'Official Live', 'ar' => 'رسمي'],
    'price' => 0,
    'currency' => 'EGP',
    'is_active' => true,
    'is_lifetime' => true,
    'max_quiz_attempts' => 5,
]);
$sync->syncPlanEntitlements($officialPlan, [
    'lesson_ids' => [$lessons[2]->id],
    'quiz_ids' => [$officialQuiz->id],
]);
$officialUser = User::factory()->create(['email' => "live.official.{$tag}@example.com"]);
$officialEnroll = $enroll->enroll($officialUser, $course, null, $officialPlan);
$service = app(QuizAttemptService::class);
$a1 = $service->start($officialUser, $officialQuiz, $officialEnroll);
$service->submit($a1, [['question_id' => $question->id, 'selected_option_ids' => [$wrong->id]]]);
$a1->refresh();
$a2 = $service->start($officialUser, $officialQuiz, $officialEnroll);
$service->submit($a2, [['question_id' => $question->id, 'selected_option_ids' => [$correct->id]]]);
$a2->refresh();
$payload = app(OfficialQuizScoreService::class)->officialPayload($officialUser, $officialQuiz, $officialEnroll);
$assert(
    'First submitted quiz is official; retry is not',
    $a1->is_official === true
        && $a2->is_official === false
        && (float) $payload['official_score'] === (float) $a1->percentage
        && (float) $a2->percentage > (float) $a1->percentage
);

$cats = Http::acceptJson()->get("{$base}/api/v1/categories");
$assert('Categories API healthy', $cats->successful());

echo PHP_EOL.'==== SUMMARY ===='.PHP_EOL;
$passed = count($results) - $fail;
echo "Passed: {$passed} / ".count($results).PHP_EOL;
echo "Failed: {$fail}".PHP_EOL;
echo "Course slug: {$course->slug}".PHP_EOL;
echo "Product slug: {$product->slug}".PHP_EOL;
echo "User: {$user->email} / Password123!".PHP_EOL;

if ($fail > 0) {
    exit(1);
}
