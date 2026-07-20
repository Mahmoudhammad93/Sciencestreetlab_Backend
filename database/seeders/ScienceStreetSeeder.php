<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class ScienceStreetSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@sciencestreetlab.com'],
            [
                'name' => 'Science Street Admin',
                'password' => Hash::make('password'),
                'locale' => 'ar',
                'email_verified_at' => now(),
            ]
        );
        $admin->assignRole('super_admin');

        $products = [
            [
                'sku' => 'SS-MICRO-001',
                'slug' => 'science-street-microscope',
                'type' => ProductType::Kit,
                'price' => 3720,
                'compare_price' => 3720,
                'name' => ['ar' => 'ميكروسكوب شارع العلوم', 'en' => 'Science Street Microscope'],
                'short_description' => ['ar' => 'ميكروسكوب تعليمي للأطفال', 'en' => 'Educational microscope for kids'],
            ],
            [
                'sku' => 'SS-GEN-001',
                'slug' => 'manual-power-generator',
                'type' => ProductType::Kit,
                'price' => 520,
                'name' => ['ar' => 'مولد الطاقة اليدوي', 'en' => 'Manual Power Generator'],
                'short_description' => ['ar' => 'تعلم توليد الكهرباء', 'en' => 'Learn to generate electricity'],
            ],
            [
                'sku' => 'SS-TRUCK-001',
                'slug' => 'gear-truck',
                'type' => ProductType::Kit,
                'price' => 653,
                'name' => ['ar' => 'شاحنة التروس', 'en' => 'Gear Truck'],
                'short_description' => ['ar' => 'اكتشف عالم التروس', 'en' => 'Discover the world of gears'],
            ],
        ];

        foreach ($products as $data) {
            Product::query()->updateOrCreate(
                ['sku' => $data['sku']],
                array_merge($data, [
                    'status' => ProductStatus::Published,
                    'currency' => 'EGP',
                    'is_featured' => true,
                    'published_at' => now(),
                ])
            );
        }

        $microscopeProduct = Product::query()->where('sku', 'SS-MICRO-001')->first();

        $course = Course::query()->updateOrCreate(
            ['slug' => 'microscope-course'],
            [
                'product_id' => $microscopeProduct?->id,
                'access_type' => AccessType::Paid,
                'is_published' => true,
                'published_at' => now(),
                'title' => ['ar' => 'كورس الميكروسكوب', 'en' => 'Microscope Course'],
                'description' => ['ar' => 'تعلم استخدام الميكروسكوب خطوة بخطوة', 'en' => 'Learn microscope usage step by step'],
            ]
        );

        $certificateTemplate = \App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate::query()->updateOrCreate(
            ['slug' => 'default'],
            [
                'is_active' => true,
                'name' => ['ar' => 'شهادة افتراضية', 'en' => 'Default Certificate'],
                'layout_config' => ['page_size' => 'A4_landscape'],
            ]
        );

        $course->update(['certificate_template_id' => $certificateTemplate->id]);

        if ($microscopeProduct) {
            $microscopeProduct->update(['course_id' => $course->id]);
        }

        $lesson = Lesson::query()->updateOrCreate(
            ['course_id' => $course->id, 'slug' => 'introduction'],
            [
                'lesson_type' => 'theory',
                'sort_order' => 1,
                'title' => ['ar' => 'المقدمة', 'en' => 'Introduction'],
            ]
        );

        Topic::query()->updateOrCreate(
            ['lesson_id' => $lesson->id, 'slug' => 'what-is-microscope'],
            [
                'sort_order' => 1,
                'content_type' => 'video',
                'video_url' => 'https://example.com/videos/microscope-intro.mp4',
                'video_provider' => 's3',
                'title' => ['ar' => 'ما هو الميكروسكوب؟', 'en' => 'What is a microscope?'],
            ]
        );

        $quiz = \App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz::query()->updateOrCreate(
            [
                'quizable_type' => Lesson::class,
                'quizable_id' => $lesson->id,
            ],
            [
                'passing_score' => 70,
                'max_attempts' => 3,
                'is_required' => true,
                'title' => ['ar' => 'وقت التحدى', 'en' => 'Challenge Time'],
                'instructions' => ['ar' => 'أجب على الأسئلة التالية', 'en' => 'Answer the following questions'],
            ]
        );

        $question = \App\Modules\Assessment\Infrastructure\Persistence\Models\Question::query()->updateOrCreate(
            ['quiz_id' => $quiz->id, 'sort_order' => 1],
            [
                'question_type' => \App\Modules\Assessment\Domain\Enums\QuestionType::SingleChoice,
                'points' => 1,
                'body' => ['ar' => 'ما وظيفة الميكروسكوب؟', 'en' => 'What is the function of a microscope?'],
            ]
        );

        \App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption::query()->updateOrCreate(
            ['question_id' => $question->id, 'sort_order' => 1],
            [
                'is_correct' => true,
                'label' => ['ar' => 'تكبير الأجسام الدقيقة', 'en' => 'Magnify tiny objects'],
            ]
        );

        \App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption::query()->updateOrCreate(
            ['question_id' => $question->id, 'sort_order' => 2],
            [
                'is_correct' => false,
                'label' => ['ar' => 'تسخين المواد', 'en' => 'Heat materials'],
            ]
        );

        Competition::query()->updateOrCreate(
            ['slug' => 'microscope-100-challenge'],
            [
                'prerequisite_course_id' => $course->id,
                'required_photos' => 100,
                'photos_per_sample' => 2,
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->addMonths(6),
                'status' => 'active',
                'prize_amount' => 100000,
                'title' => ['ar' => 'تحدي ال100 صورة', 'en' => '100 Photo Challenge'],
                'description' => ['ar' => 'تحدي تصوير 100 صورة بالميكروسكوب', 'en' => '100 microscope photo challenge'],
            ]
        );

        \App\Modules\Commerce\Infrastructure\Persistence\Models\Coupon::query()->updateOrCreate(
            ['code' => 'SCIENCE10'],
            [
                'type' => \App\Modules\Commerce\Domain\Enums\CouponType::Percentage,
                'value' => 10,
                'min_order_amount' => 100,
                'is_active' => true,
                'starts_at' => now()->subDay(),
                'expires_at' => now()->addYear(),
            ]
        );

        $this->seedAchievements();
        $this->seedContentPages($admin);
    }

    private function seedAchievements(): void
    {
        $courseGraduate = \App\Modules\Gamification\Infrastructure\Persistence\Models\Achievement::query()->updateOrCreate(
            ['slug' => 'course-graduate'],
            [
                'category' => \App\Modules\Gamification\Domain\Enums\AchievementCategory::Course,
                'points' => 50,
                'badge_color' => '#2828a0',
                'is_active' => true,
                'name' => ['ar' => 'متخرج', 'en' => 'Graduate'],
                'description' => ['ar' => 'أتممت أول دورة', 'en' => 'Completed your first course'],
            ]
        );

        \App\Modules\Gamification\Infrastructure\Persistence\Models\AchievementRule::query()->updateOrCreate(
            ['achievement_id' => $courseGraduate->id, 'trigger_event' => \App\Modules\Learning\Domain\Events\CourseCompleted::class],
            ['conditions' => []]
        );

        $microscopeCert = \App\Modules\Gamification\Infrastructure\Persistence\Models\Achievement::query()->updateOrCreate(
            ['slug' => 'microscope-certified'],
            [
                'category' => \App\Modules\Gamification\Domain\Enums\AchievementCategory::Course,
                'points' => 100,
                'badge_color' => '#fcd500',
                'is_active' => true,
                'name' => ['ar' => 'خبير الميكروسكوب', 'en' => 'Microscope Expert'],
                'description' => ['ar' => 'أتممت كورس الميكروسكوب', 'en' => 'Completed the microscope course'],
            ]
        );

        \App\Modules\Gamification\Infrastructure\Persistence\Models\AchievementRule::query()->updateOrCreate(
            ['achievement_id' => $microscopeCert->id, 'trigger_event' => \App\Modules\Learning\Domain\Events\CourseCompleted::class],
            ['conditions' => ['course_slug' => 'microscope-course']]
        );

        $quizMaster = \App\Modules\Gamification\Infrastructure\Persistence\Models\Achievement::query()->updateOrCreate(
            ['slug' => 'quiz-master'],
            [
                'category' => \App\Modules\Gamification\Domain\Enums\AchievementCategory::Quiz,
                'points' => 25,
                'badge_color' => '#2828a0',
                'is_active' => true,
                'name' => ['ar' => 'سيد الاختبارات', 'en' => 'Quiz Master'],
                'description' => ['ar' => 'اجتزت اختباراً بنجاح', 'en' => 'Passed a quiz'],
            ]
        );

        \App\Modules\Gamification\Infrastructure\Persistence\Models\AchievementRule::query()->updateOrCreate(
            ['achievement_id' => $quizMaster->id, 'trigger_event' => \App\Modules\Assessment\Domain\Events\QuizPassed::class],
            ['conditions' => ['passed_count' => ['gte' => 1]]]
        );

        $photoPioneer = \App\Modules\Gamification\Infrastructure\Persistence\Models\Achievement::query()->updateOrCreate(
            ['slug' => 'photo-pioneer'],
            [
                'category' => \App\Modules\Gamification\Domain\Enums\AchievementCategory::Competition,
                'points' => 30,
                'badge_color' => '#2828a0',
                'is_active' => true,
                'name' => ['ar' => 'رائد التصوير', 'en' => 'Photo Pioneer'],
                'description' => ['ar' => 'تمت الموافقة على أول صورة في التحدي', 'en' => 'First competition photo approved'],
            ]
        );

        \App\Modules\Gamification\Infrastructure\Persistence\Models\AchievementRule::query()->updateOrCreate(
            ['achievement_id' => $photoPioneer->id, 'trigger_event' => \App\Modules\Competition\Domain\Events\CompetitionSubmissionApproved::class],
            ['conditions' => ['approved_count' => ['gte' => 1]]]
        );
    }

    private function seedContentPages(User $admin): void
    {
        \App\Modules\Content\Infrastructure\Persistence\Models\Page::query()->updateOrCreate(
            ['slug' => 'about'],
            [
                'template' => 'default',
                'is_published' => true,
                'published_at' => now(),
                'title' => ['ar' => 'من نحن', 'en' => 'About Us'],
                'content' => [
                    'ar' => '<p>شارع العلوم — منصة تعليم STEM للأطفال في مصر.</p>',
                    'en' => '<p>Science Street Lab — STEM education for kids in Egypt.</p>',
                ],
            ]
        );

        \App\Modules\Content\Infrastructure\Persistence\Models\Post::query()->updateOrCreate(
            ['slug' => 'welcome-to-science-street'],
            [
                'author_id' => $admin->id,
                'is_published' => true,
                'published_at' => now(),
                'title' => ['ar' => 'مرحباً بكم في شارع العلوم', 'en' => 'Welcome to Science Street'],
                'excerpt' => [
                    'ar' => 'اكتشف عالم العلوم مع مجموعاتنا التعليمية',
                    'en' => 'Discover science with our educational kits',
                ],
                'content' => [
                    'ar' => '<p>نقدم تجربة تعليمية متكاملة تجمع بين الأدوات والدورات والتحديات.</p>',
                    'en' => '<p>We offer an integrated learning experience combining kits, courses, and challenges.</p>',
                ],
            ]
        );
    }
}
