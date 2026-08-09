<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\LessonCompletion;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use App\Modules\Learning\Infrastructure\Persistence\Models\TopicCompletion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds 10 courses with varied access types, lesson types, quizzes, and
 * enrollment states so the full e-learning cycle can be exercised in Postman.
 */
final class LearningCatalogSeeder extends Seeder
{
    private const IMAGE = 'https://sciencestreetlab.com/wp-content/uploads/2026/01/download-37.jpg';

    private CertificateTemplate $certificateTemplate;

    public function run(): void
    {
        $this->certificateTemplate = CertificateTemplate::query()->updateOrCreate(
            ['slug' => 'default'],
            [
                'is_active' => true,
                'name' => ['ar' => 'شهادة افتراضية', 'en' => 'Default Certificate'],
                'layout_config' => ['page_size' => 'A4_landscape'],
            ]
        );

        $products = $this->seedProducts();
        $courses = $this->seedCourses($products);
        $this->seedCurricula($courses);
        $this->seedTestUsersAndEnrollments($courses);
    }

    /**
     * @return array<string, Product>
     */
    private function seedProducts(): array
    {
        $definitions = [
            'SS-MICRO-001' => [
                'slug' => 'science-street-microscope',
                'type' => ProductType::Kit,
                'price' => 3720,
                'compare_price' => 3720,
                'name' => ['ar' => 'ميكروسكوب شارع العلوم', 'en' => 'Science Street Microscope'],
                'short_description' => ['ar' => 'ميكروسكوب تعليمي للأطفال', 'en' => 'Educational microscope for kids'],
            ],
            'SS-GEN-001' => [
                'slug' => 'manual-power-generator',
                'type' => ProductType::Kit,
                'price' => 520,
                'name' => ['ar' => 'مولد الطاقة اليدوي', 'en' => 'Manual Power Generator'],
                'short_description' => ['ar' => 'تعلم توليد الكهرباء', 'en' => 'Learn to generate electricity'],
            ],
            'SS-TRUCK-001' => [
                'slug' => 'gear-truck',
                'type' => ProductType::Kit,
                'price' => 653,
                'name' => ['ar' => 'شاحنة التروس', 'en' => 'Gear Truck'],
                'short_description' => ['ar' => 'اكتشف عالم التروس', 'en' => 'Discover the world of gears'],
            ],
            'SS-CRS-DESIGN' => [
                'slug' => 'design-thinking-course-product',
                'type' => ProductType::Course,
                'price' => 299,
                'name' => ['ar' => 'كورس التفكير التصميمي', 'en' => 'Design Thinking Course'],
                'short_description' => ['ar' => 'وصول رقمي للكورس', 'en' => 'Digital course access'],
            ],
            'SS-CRS-OPTICS' => [
                'slug' => 'advanced-optics-course-product',
                'type' => ProductType::Course,
                'price' => 890,
                'name' => ['ar' => 'كورس البصريات المتقدم', 'en' => 'Advanced Optics Course'],
                'short_description' => ['ar' => 'مستوى متقدم بعد الميكروسكوب', 'en' => 'Advanced level after microscope'],
            ],
            'SS-CRS-ROBOT' => [
                'slug' => 'robotics-course-product',
                'type' => ProductType::Course,
                'price' => 1200,
                'name' => ['ar' => 'كورس الروبوتات', 'en' => 'Robotics Course'],
                'short_description' => ['ar' => 'قريباً', 'en' => 'Coming soon'],
            ],
        ];

        $products = [];

        foreach ($definitions as $sku => $data) {
            $products[$sku] = Product::query()->updateOrCreate(
                ['sku' => $sku],
                array_merge($data, [
                    'status' => ProductStatus::Published,
                    'currency' => 'EGP',
                    'is_featured' => true,
                    'published_at' => now(),
                ])
            );
        }

        return $products;
    }

    /**
     * @param  array<string, Product>  $products
     * @return array<string, Course>
     */
    private function seedCourses(array $products): array
    {
        $definitions = [
            // 1 — Paid flagship (full cycle + certificate + competition prereq)
            'microscope-course' => [
                'product_sku' => 'SS-MICRO-001',
                'access_type' => AccessType::Paid,
                'is_published' => true,
                'estimated_hours' => 4.5,
                'sort_order' => 1,
                'title' => ['ar' => 'كورس الميكروسكوب', 'en' => 'Microscope Course'],
                'short_description' => [
                    'ar' => 'تعلم الميكروسكوب خطوة بخطوة',
                    'en' => 'Learn the microscope step by step',
                ],
                'description' => [
                    'ar' => 'دورة مدفوعة كاملة: دروس متسلسلة، فيديو، اختبار مطلوب، وشهادة.',
                    'en' => 'Full paid cycle: sequential lessons, video, required quiz, and certificate.',
                ],
            ],
            // 2 — Free starter (direct enroll)
            'intro-to-science' => [
                'access_type' => AccessType::Free,
                'is_published' => true,
                'estimated_hours' => 1.5,
                'sort_order' => 2,
                'title' => ['ar' => 'مقدمة في العلوم', 'en' => 'Intro to Science'],
                'short_description' => [
                    'ar' => 'كورس مجاني للمبتدئين',
                    'en' => 'A free starter course',
                ],
                'description' => [
                    'ar' => 'سجّل مباشرة بدون دفع. درس واحد وفيديو واختبار اختياري.',
                    'en' => 'Enroll directly with no payment. One lesson, video, optional quiz.',
                ],
            ],
            // 3 — Paid + electricity kit product
            'electricity-basics' => [
                'product_sku' => 'SS-GEN-001',
                'access_type' => AccessType::Paid,
                'is_published' => true,
                'estimated_hours' => 3.0,
                'sort_order' => 3,
                'title' => ['ar' => 'أساسيات الكهرباء', 'en' => 'Electricity Basics'],
                'short_description' => [
                    'ar' => 'توليد الكهرباء عملياً',
                    'en' => 'Hands-on electricity generation',
                ],
                'description' => [
                    'ar' => 'كورس مدفوع مرتبط بمنتج المولد. دروس assembly + theory.',
                    'en' => 'Paid course linked to generator kit. Assembly + theory lessons.',
                ],
            ],
            // 4 — Paid + gear truck, assembly focus
            'gear-mechanics' => [
                'product_sku' => 'SS-TRUCK-001',
                'access_type' => AccessType::Paid,
                'is_published' => true,
                'estimated_hours' => 2.5,
                'sort_order' => 4,
                'title' => ['ar' => 'ميكانيكا التروس', 'en' => 'Gear Mechanics'],
                'short_description' => [
                    'ar' => 'اكتشف عالم التروس',
                    'en' => 'Discover the world of gears',
                ],
                'description' => [
                    'ar' => 'دروس تركيب (assembly) مع فيديوهات متعددة لكل درس.',
                    'en' => 'Assembly lessons with multiple videos per lesson.',
                ],
            ],
            // 5 — School-only (enroll should fail with SCHOOL_REQUIRED)
            'school-lab-safety' => [
                'access_type' => AccessType::School,
                'is_published' => true,
                'estimated_hours' => 2.0,
                'sort_order' => 5,
                'title' => ['ar' => 'سلامة المعمل المدرسي', 'en' => 'School Lab Safety'],
                'short_description' => [
                    'ar' => 'للمدارس فقط',
                    'en' => 'Schools only',
                ],
                'description' => [
                    'ar' => 'الوصول عبر عضوية المدرسة فقط. اختبار enroll يرجع 403.',
                    'en' => 'School membership only. Enroll API returns 403.',
                ],
            ],
            // 6 — Free creativity lab, no quiz
            'creative-inventors' => [
                'access_type' => AccessType::Free,
                'is_published' => true,
                'estimated_hours' => 2.0,
                'sort_order' => 6,
                'title' => ['ar' => 'المخترعون المبدعون', 'en' => 'Creative Inventors'],
                'short_description' => [
                    'ar' => 'مختبر إبداع مجاني',
                    'en' => 'Free creativity lab',
                ],
                'description' => [
                    'ar' => 'دروس creativity_lab بدون اختبار — اكتمال بالفيديو فقط.',
                    'en' => 'Creativity-lab lessons with no quiz — complete via video only.',
                ],
            ],
            // 7 — Paid design lab + digital course product
            'design-thinking-lab' => [
                'product_sku' => 'SS-CRS-DESIGN',
                'access_type' => AccessType::Paid,
                'is_published' => true,
                'estimated_hours' => 5.0,
                'sort_order' => 7,
                'title' => ['ar' => 'مختبر التفكير التصميمي', 'en' => 'Design Thinking Lab'],
                'short_description' => [
                    'ar' => 'من الفكرة إلى النموذج',
                    'en' => 'From idea to prototype',
                ],
                'description' => [
                    'ar' => 'دروس design_lab مع اختبارات اختيارية وغير مطلوبة.',
                    'en' => 'Design-lab lessons with optional (non-required) quizzes.',
                ],
            ],
            // 8 — Unpublished / coming soon (should 404 on public detail)
            'coming-soon-robotics' => [
                'product_sku' => 'SS-CRS-ROBOT',
                'access_type' => AccessType::Paid,
                'is_published' => false,
                'estimated_hours' => 6.0,
                'sort_order' => 8,
                'title' => ['ar' => 'الروبوتات (قريباً)', 'en' => 'Robotics (Coming Soon)'],
                'short_description' => [
                    'ar' => 'غير منشور بعد',
                    'en' => 'Not published yet',
                ],
                'description' => [
                    'ar' => 'يظهر في الأدمن فقط — API العام يرجع 404.',
                    'en' => 'Admin only — public API returns 404.',
                ],
            ],
            // 9 — Closed for enrollment
            'archived-chemistry' => [
                'access_type' => AccessType::Closed,
                'is_published' => true,
                'estimated_hours' => 3.0,
                'sort_order' => 9,
                'title' => ['ar' => 'كيمياء أرشيفية', 'en' => 'Archived Chemistry'],
                'short_description' => [
                    'ar' => 'مغلق للتسجيل',
                    'en' => 'Closed for enrollment',
                ],
                'description' => [
                    'ar' => 'منشور للعرض لكن enroll يرجع 403 ENROLLMENT_FORBIDDEN.',
                    'en' => 'Visible in catalog but enroll returns 403 ENROLLMENT_FORBIDDEN.',
                ],
            ],
            // 10 — Paid advanced with course prerequisite
            'advanced-optics' => [
                'product_sku' => 'SS-CRS-OPTICS',
                'access_type' => AccessType::Paid,
                'is_published' => true,
                'estimated_hours' => 7.0,
                'sort_order' => 10,
                'prerequisite' => 'microscope-course',
                'title' => ['ar' => 'البصريات المتقدمة', 'en' => 'Advanced Optics'],
                'short_description' => [
                    'ar' => 'يتطلب إنهاء كورس الميكروسكوب',
                    'en' => 'Requires completing Microscope Course',
                ],
                'description' => [
                    'ar' => 'كورس مدفوع متقدم مع متطلب سابق + دروس متعددة + اختبارات مطلوبة.',
                    'en' => 'Advanced paid course with prerequisite + multi lessons + required quizzes.',
                ],
            ],
        ];

        $courses = [];

        foreach ($definitions as $slug => $data) {
            $product = isset($data['product_sku']) ? $products[$data['product_sku']] : null;

            $course = Course::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'product_id' => $product?->id,
                    'access_type' => $data['access_type'],
                    'is_published' => $data['is_published'],
                    'published_at' => $data['is_published'] ? now() : null,
                    'estimated_hours' => $data['estimated_hours'],
                    'sort_order' => $data['sort_order'],
                    'image_url' => self::IMAGE,
                    'certificate_template_id' => $this->certificateTemplate->id,
                    'title' => $data['title'],
                    'short_description' => $data['short_description'],
                    'description' => $data['description'],
                ]
            );

            if ($product) {
                $product->update(['course_id' => $course->id]);
            }

            $courses[$slug] = $course;
        }

        // Wire prerequisite after both courses exist
        $courses['advanced-optics']->update([
            'prerequisite_course_id' => $courses['microscope-course']->id,
        ]);

        return $courses;
    }

    /**
     * @param  array<string, Course>  $courses
     */
    private function seedCurricula(array $courses): void
    {
        // 1) Microscope — keep legacy single-lesson flow used by feature/cert tests
        $this->seedLessonTree($courses['microscope-course'], [
            [
                'slug' => 'introduction',
                'type' => LessonType::Theory,
                'title' => ['ar' => 'المقدمة', 'en' => 'Introduction'],
                'content' => ['ar' => 'محتوى مقدمة الميكروسكوب', 'en' => 'Microscope intro content'],
                'topics' => [
                    [
                        'slug' => 'what-is-microscope',
                        'title' => ['ar' => 'ما هو الميكروسكوب؟', 'en' => 'What is a microscope?'],
                        'video_url' => 'https://example.com/videos/microscope-intro.mp4',
                        'content' => ['ar' => 'شرح الجهاز', 'en' => 'Device overview'],
                    ],
                ],
                'quiz' => [
                    'required' => true,
                    'max_attempts' => 3,
                    'passing_score' => 70,
                    'title' => ['ar' => 'وقت التحدى', 'en' => 'Challenge Time'],
                    'questions' => [
                        [
                            'type' => QuestionType::SingleChoice,
                            'body' => ['ar' => 'ما وظيفة الميكروسكوب؟', 'en' => 'What is the function of a microscope?'],
                            'options' => [
                                ['label' => ['ar' => 'تكبير الأجسام الدقيقة', 'en' => 'Magnify tiny objects'], 'correct' => true],
                                ['label' => ['ar' => 'تسخين المواد', 'en' => 'Heat materials'], 'correct' => false],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // 2) Free intro
        $this->seedLessonTree($courses['intro-to-science'], [
            [
                'slug' => 'welcome',
                'type' => LessonType::Theory,
                'title' => ['ar' => 'مرحباً بالعلوم', 'en' => 'Welcome to Science'],
                'topics' => [
                    [
                        'slug' => 'what-is-stem',
                        'title' => ['ar' => 'ما هو STEM؟', 'en' => 'What is STEM?'],
                        'video_url' => 'https://example.com/videos/stem-intro.mp4',
                    ],
                ],
                'quiz' => [
                    'required' => false,
                    'max_attempts' => null,
                    'passing_score' => 50,
                    'title' => ['ar' => 'اختبار سريع', 'en' => 'Quick Check'],
                    'questions' => [
                        [
                            'type' => QuestionType::ShortAnswer,
                            'body' => ['ar' => 'اذكر حرفاً من STEM', 'en' => 'Name one letter from STEM'],
                            'options' => [],
                        ],
                    ],
                ],
            ],
        ]);

        // 3) Electricity
        $this->seedLessonTree($courses['electricity-basics'], [
            [
                'slug' => 'circuits-theory',
                'type' => LessonType::Theory,
                'title' => ['ar' => 'نظرية الدوائر', 'en' => 'Circuit Theory'],
                'topics' => [
                    [
                        'slug' => 'current-voltage',
                        'title' => ['ar' => 'التيار والجهد', 'en' => 'Current and voltage'],
                        'video_url' => 'https://example.com/videos/electricity-1.mp4',
                    ],
                ],
                'quiz' => [
                    'required' => true,
                    'max_attempts' => 3,
                    'passing_score' => 70,
                    'title' => ['ar' => 'اختبار الدوائر', 'en' => 'Circuits Quiz'],
                    'questions' => [
                        [
                            'type' => QuestionType::MultipleChoice,
                            'body' => ['ar' => 'أي العناصر توصل الكهرباء؟', 'en' => 'Which materials conduct electricity?'],
                            'options' => [
                                ['label' => ['ar' => 'النحاس', 'en' => 'Copper'], 'correct' => true],
                                ['label' => ['ar' => 'الألومنيوم', 'en' => 'Aluminum'], 'correct' => true],
                                ['label' => ['ar' => 'المطاط', 'en' => 'Rubber'], 'correct' => false],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'build-generator',
                'type' => LessonType::Assembly,
                'title' => ['ar' => 'بناء المولد', 'en' => 'Build the Generator'],
                'topics' => [
                    [
                        'slug' => 'assemble-parts',
                        'title' => ['ar' => 'تجميع الأجزاء', 'en' => 'Assemble parts'],
                        'video_url' => 'https://example.com/videos/electricity-assemble.mp4',
                    ],
                    [
                        'slug' => 'test-output',
                        'title' => ['ar' => 'اختبار الخرج', 'en' => 'Test output'],
                        'video_url' => 'https://example.com/videos/electricity-test.mp4',
                    ],
                ],
            ],
        ]);

        // 4) Gears
        $this->seedLessonTree($courses['gear-mechanics'], [
            [
                'slug' => 'gear-ratios',
                'type' => LessonType::Assembly,
                'title' => ['ar' => 'سلاسل التروس', 'en' => 'Gear Trains'],
                'topics' => [
                    [
                        'slug' => 'ratio-basics',
                        'title' => ['ar' => 'أساسيات النسبة', 'en' => 'Ratio basics'],
                        'video_url' => 'https://example.com/videos/gears-1.mp4',
                    ],
                    [
                        'slug' => 'build-truck',
                        'title' => ['ar' => 'بناء الشاحنة', 'en' => 'Build the truck'],
                        'video_url' => 'https://example.com/videos/gears-2.mp4',
                    ],
                ],
                'quiz' => [
                    'required' => true,
                    'max_attempts' => 1,
                    'passing_score' => 100,
                    'title' => ['ar' => 'اختبار محاولة واحدة', 'en' => 'Single Attempt Quiz'],
                    'questions' => [
                        [
                            'type' => QuestionType::SingleChoice,
                            'body' => ['ar' => 'الترس الأكبر يجعل الحركة؟', 'en' => 'A larger gear makes motion?'],
                            'options' => [
                                ['label' => ['ar' => 'أبطأ وبقوة أكبر', 'en' => 'Slower with more torque'], 'correct' => true],
                                ['label' => ['ar' => 'أسرع دائماً', 'en' => 'Always faster'], 'correct' => false],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // 5) School safety
        $this->seedLessonTree($courses['school-lab-safety'], [
            [
                'slug' => 'safety-rules',
                'type' => LessonType::Theory,
                'title' => ['ar' => 'قواعد السلامة', 'en' => 'Safety Rules'],
                'topics' => [
                    [
                        'slug' => 'lab-dos-donts',
                        'title' => ['ar' => 'افعل ولا تفعل', 'en' => 'Dos and don\'ts'],
                        'video_url' => 'https://example.com/videos/safety.mp4',
                    ],
                ],
                'quiz' => [
                    'required' => true,
                    'max_attempts' => 5,
                    'passing_score' => 60,
                    'title' => ['ar' => 'اختبار السلامة', 'en' => 'Safety Quiz'],
                    'questions' => [
                        [
                            'type' => QuestionType::TrueFalse,
                            'body' => ['ar' => 'ارتداء النظارة الواقية إلزامي؟', 'en' => 'Safety goggles are mandatory?'],
                            'options' => [
                                ['label' => ['ar' => 'صح', 'en' => 'True'], 'correct' => true],
                                ['label' => ['ar' => 'خطأ', 'en' => 'False'], 'correct' => false],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // 6) Creative inventors — no quiz
        $this->seedLessonTree($courses['creative-inventors'], [
            [
                'slug' => 'ideation',
                'type' => LessonType::CreativityLab,
                'title' => ['ar' => 'توليد الأفكار', 'en' => 'Ideation'],
                'topics' => [
                    [
                        'slug' => 'brainstorm',
                        'title' => ['ar' => 'عصف ذهني', 'en' => 'Brainstorm'],
                        'video_url' => 'https://example.com/videos/creative-1.mp4',
                        'content' => ['ar' => 'اكتب 10 أفكار', 'en' => 'Write 10 ideas'],
                    ],
                ],
            ],
            [
                'slug' => 'prototype',
                'type' => LessonType::CreativityLab,
                'title' => ['ar' => 'النموذج الأولي', 'en' => 'Prototype'],
                'topics' => [
                    [
                        'slug' => 'build-rough-model',
                        'title' => ['ar' => 'بناء نموذج أولي', 'en' => 'Build a rough model'],
                        'video_url' => 'https://example.com/videos/creative-2.mp4',
                    ],
                    [
                        'slug' => 'share-story',
                        'title' => ['ar' => 'شارك قصتك', 'en' => 'Share your story'],
                        'content_type' => 'text',
                        'content' => ['ar' => 'اكتب قصة اختراعك', 'en' => 'Write your invention story'],
                    ],
                ],
            ],
        ]);

        // 7) Design thinking — optional quizzes
        $this->seedLessonTree($courses['design-thinking-lab'], [
            [
                'slug' => 'empathize',
                'type' => LessonType::DesignLab,
                'title' => ['ar' => 'التعاطف', 'en' => 'Empathize'],
                'topics' => [
                    [
                        'slug' => 'user-interview',
                        'title' => ['ar' => 'مقابلة المستخدم', 'en' => 'User interview'],
                        'video_url' => 'https://example.com/videos/design-1.mp4',
                    ],
                ],
                'quiz' => [
                    'required' => false,
                    'max_attempts' => 10,
                    'passing_score' => 50,
                    'title' => ['ar' => 'اختبار اختياري', 'en' => 'Optional Quiz'],
                    'questions' => [
                        [
                            'type' => QuestionType::SingleChoice,
                            'body' => ['ar' => 'أول خطوة في التفكير التصميمي؟', 'en' => 'First step in design thinking?'],
                            'options' => [
                                ['label' => ['ar' => 'التعاطف', 'en' => 'Empathize'], 'correct' => true],
                                ['label' => ['ar' => 'البناء مباشرة', 'en' => 'Build immediately'], 'correct' => false],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'prototype-test',
                'type' => LessonType::DesignLab,
                'title' => ['ar' => 'النموذج والاختبار', 'en' => 'Prototype & Test'],
                'topics' => [
                    [
                        'slug' => 'low-fi-prototype',
                        'title' => ['ar' => 'نموذج منخفض الدقة', 'en' => 'Low-fi prototype'],
                        'video_url' => 'https://example.com/videos/design-2.mp4',
                    ],
                    [
                        'slug' => 'feedback-loop',
                        'title' => ['ar' => 'حلقة الملاحظات', 'en' => 'Feedback loop'],
                        'video_url' => 'https://example.com/videos/design-3.mp4',
                    ],
                ],
            ],
        ]);

        // 8) Unpublished robotics (still has content for admin)
        $this->seedLessonTree($courses['coming-soon-robotics'], [
            [
                'slug' => 'robot-intro',
                'type' => LessonType::Theory,
                'title' => ['ar' => 'مقدمة الروبوتات', 'en' => 'Robotics Intro'],
                'topics' => [
                    [
                        'slug' => 'what-is-a-robot',
                        'title' => ['ar' => 'ما هو الروبوت؟', 'en' => 'What is a robot?'],
                        'video_url' => 'https://example.com/videos/robot-1.mp4',
                    ],
                ],
            ],
        ]);

        // 9) Closed chemistry
        $this->seedLessonTree($courses['archived-chemistry'], [
            [
                'slug' => 'atoms',
                'type' => LessonType::Theory,
                'title' => ['ar' => 'الذرات', 'en' => 'Atoms'],
                'topics' => [
                    [
                        'slug' => 'atom-model',
                        'title' => ['ar' => 'نموذج الذرة', 'en' => 'Atom model'],
                        'video_url' => 'https://example.com/videos/chem-1.mp4',
                    ],
                ],
                'quiz' => [
                    'required' => true,
                    'max_attempts' => 3,
                    'passing_score' => 70,
                    'title' => ['ar' => 'اختبار الذرات', 'en' => 'Atoms Quiz'],
                    'questions' => [
                        [
                            'type' => QuestionType::SingleChoice,
                            'body' => ['ar' => 'أصغر جزء في العنصر؟', 'en' => 'Smallest unit of an element?'],
                            'options' => [
                                ['label' => ['ar' => 'الذرة', 'en' => 'Atom'], 'correct' => true],
                                ['label' => ['ar' => 'الجزيء', 'en' => 'Molecule'], 'correct' => false],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // 10) Advanced optics — multi-lesson lock chain
        $this->seedLessonTree($courses['advanced-optics'], [
            [
                'slug' => 'light-waves',
                'type' => LessonType::Theory,
                'title' => ['ar' => 'موجات الضوء', 'en' => 'Light Waves'],
                'topics' => [
                    [
                        'slug' => 'wavelength',
                        'title' => ['ar' => 'الطول الموجي', 'en' => 'Wavelength'],
                        'video_url' => 'https://example.com/videos/optics-1.mp4',
                    ],
                ],
                'quiz' => [
                    'required' => true,
                    'max_attempts' => 3,
                    'passing_score' => 75,
                    'title' => ['ar' => 'اختبار الموجات', 'en' => 'Waves Quiz'],
                    'questions' => [
                        [
                            'type' => QuestionType::TrueFalse,
                            'body' => ['ar' => 'الضوء موجة كهرومغناطيسية؟', 'en' => 'Light is an electromagnetic wave?'],
                            'options' => [
                                ['label' => ['ar' => 'صح', 'en' => 'True'], 'correct' => true],
                                ['label' => ['ar' => 'خطأ', 'en' => 'False'], 'correct' => false],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'lenses',
                'type' => LessonType::Theory,
                'title' => ['ar' => 'العدسات', 'en' => 'Lenses'],
                'topics' => [
                    [
                        'slug' => 'convex-concave',
                        'title' => ['ar' => 'محدبة ومقعرة', 'en' => 'Convex and concave'],
                        'video_url' => 'https://example.com/videos/optics-2.mp4',
                    ],
                    [
                        'slug' => 'image-formation',
                        'title' => ['ar' => 'تكوين الصورة', 'en' => 'Image formation'],
                        'video_url' => 'https://example.com/videos/optics-3.mp4',
                    ],
                ],
                'quiz' => [
                    'required' => true,
                    'max_attempts' => 2,
                    'passing_score' => 80,
                    'title' => ['ar' => 'اختبار العدسات', 'en' => 'Lenses Quiz'],
                    'questions' => [
                        [
                            'type' => QuestionType::SingleChoice,
                            'body' => ['ar' => 'العدسة المحدبة؟', 'en' => 'A convex lens?'],
                            'options' => [
                                ['label' => ['ar' => 'تجمع الضوء', 'en' => 'Converges light'], 'correct' => true],
                                ['label' => ['ar' => 'تفرق الضوء', 'en' => 'Diverges light'], 'correct' => false],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'optics-lab',
                'type' => LessonType::DesignLab,
                'title' => ['ar' => 'معمل البصريات', 'en' => 'Optics Lab'],
                'topics' => [
                    [
                        'slug' => 'build-simple-scope',
                        'title' => ['ar' => 'بناء منظار بسيط', 'en' => 'Build a simple scope'],
                        'video_url' => 'https://example.com/videos/optics-lab.mp4',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lessons
     */
    private function seedLessonTree(Course $course, array $lessons): void
    {
        foreach ($lessons as $index => $lessonData) {
            $lesson = Lesson::query()->updateOrCreate(
                ['course_id' => $course->id, 'slug' => $lessonData['slug']],
                [
                    'lesson_type' => $lessonData['type']->value,
                    'sort_order' => $index + 1,
                    'is_published' => true,
                    'title' => $lessonData['title'],
                    'content' => $lessonData['content'] ?? [
                        'ar' => $lessonData['title']['ar'],
                        'en' => $lessonData['title']['en'],
                    ],
                ]
            );

            foreach ($lessonData['topics'] as $topicIndex => $topicData) {
                Topic::query()->updateOrCreate(
                    ['lesson_id' => $lesson->id, 'slug' => $topicData['slug']],
                    [
                        'sort_order' => $topicIndex + 1,
                        'content_type' => $topicData['content_type'] ?? 'video',
                        'video_url' => $topicData['video_url'] ?? null,
                        'video_provider' => isset($topicData['video_url']) ? 's3' : null,
                        'is_published' => true,
                        'title' => $topicData['title'],
                        'content' => $topicData['content'] ?? null,
                    ]
                );
            }

            if (! isset($lessonData['quiz'])) {
                continue;
            }

            $quizData = $lessonData['quiz'];
            $quiz = Quiz::query()->updateOrCreate(
                [
                    'quizable_type' => Lesson::class,
                    'quizable_id' => $lesson->id,
                ],
                [
                    'passing_score' => $quizData['passing_score'],
                    'max_attempts' => $quizData['max_attempts'],
                    'is_required' => $quizData['required'],
                    'title' => $quizData['title'],
                    'instructions' => [
                        'ar' => 'أجب على الأسئلة التالية',
                        'en' => 'Answer the following questions',
                    ],
                ]
            );

            foreach ($quizData['questions'] as $qIndex => $questionData) {
                $question = Question::query()->updateOrCreate(
                    ['quiz_id' => $quiz->id, 'sort_order' => $qIndex + 1],
                    [
                        'question_type' => $questionData['type'],
                        'points' => 1,
                        'body' => $questionData['body'],
                    ]
                );

                foreach ($questionData['options'] as $oIndex => $optionData) {
                    QuestionOption::query()->updateOrCreate(
                        ['question_id' => $question->id, 'sort_order' => $oIndex + 1],
                        [
                            'is_correct' => $optionData['correct'],
                            'label' => $optionData['label'],
                        ]
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, Course>  $courses
     */
    private function seedTestUsersAndEnrollments(array $courses): void
    {
        $users = [
            'student.free@sciencestreetlab.com' => [
                'name' => 'Free Student',
                'locale' => 'ar',
            ],
            'student.progress@sciencestreetlab.com' => [
                'name' => 'In-Progress Student',
                'locale' => 'en',
            ],
            'student.done@sciencestreetlab.com' => [
                'name' => 'Completed Student',
                'locale' => 'ar',
            ],
            'student.expired@sciencestreetlab.com' => [
                'name' => 'Expired Student',
                'locale' => 'en',
            ],
            'student.suspended@sciencestreetlab.com' => [
                'name' => 'Suspended Student',
                'locale' => 'ar',
            ],
        ];

        $created = [];
        foreach ($users as $email => $data) {
            $created[$email] = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'locale' => $data['locale'],
                    'email_verified_at' => now(),
                ]
            );
        }

        // Free student — enrolled in free courses, ready to continue
        $free = $created['student.free@sciencestreetlab.com'];
        $this->enrollment($free, $courses['intro-to-science'], EnrollmentStatus::Active, 0);
        $this->enrollment($free, $courses['creative-inventors'], EnrollmentStatus::Active, 0);

        // In-progress — mid microscope course (lesson 1 video done, quiz not done)
        $progress = $created['student.progress@sciencestreetlab.com'];
        $microEnrollment = $this->enrollment($progress, $courses['microscope-course'], EnrollmentStatus::Active, 25);
        $introLesson = Lesson::query()
            ->where('course_id', $courses['microscope-course']->id)
            ->where('slug', 'introduction')
            ->first();
        $introTopic = Topic::query()
            ->where('lesson_id', $introLesson?->id)
            ->where('slug', 'what-is-microscope')
            ->first();

        if ($introTopic) {
            TopicCompletion::query()->updateOrCreate(
                ['enrollment_id' => $microEnrollment->id, 'topic_id' => $introTopic->id],
                [
                    'watch_progress_percent' => 95,
                    'watched_seconds' => 570,
                    'duration_seconds' => 600,
                    'last_position_seconds' => 570,
                    'completed_at' => now()->subHour(),
                ]
            );
            $microEnrollment->update([
                'last_accessed_lesson_id' => $introLesson->id,
                'last_accessed_topic_id' => $introTopic->id,
                'last_accessed_at' => now()->subMinutes(10),
            ]);
        }

        // Completed student — finished creative inventors (video-only course)
        $done = $created['student.done@sciencestreetlab.com'];
        $creativeEnrollment = $this->enrollment($done, $courses['creative-inventors'], EnrollmentStatus::Completed, 100, completed: true);
        $creativeLessons = Lesson::query()
            ->where('course_id', $courses['creative-inventors']->id)
            ->where('is_published', true)
            ->get();

        foreach ($creativeLessons as $lesson) {
            LessonCompletion::query()->updateOrCreate(
                ['enrollment_id' => $creativeEnrollment->id, 'lesson_id' => $lesson->id],
                ['completed_at' => now()->subDay()]
            );

            foreach ($lesson->topics as $topic) {
                TopicCompletion::query()->updateOrCreate(
                    ['enrollment_id' => $creativeEnrollment->id, 'topic_id' => $topic->id],
                    [
                        'watch_progress_percent' => 100,
                        'watched_seconds' => 300,
                        'duration_seconds' => 300,
                        'last_position_seconds' => 300,
                        'completed_at' => now()->subDay(),
                    ]
                );
            }
        }

        // Expired enrollment on closed course (edge case)
        $expired = $created['student.expired@sciencestreetlab.com'];
        $this->enrollment(
            $expired,
            $courses['archived-chemistry'],
            EnrollmentStatus::Expired,
            40,
            expiresAt: now()->subWeek()
        );

        // Suspended on school course
        $suspended = $created['student.suspended@sciencestreetlab.com'];
        $this->enrollment(
            $suspended,
            $courses['school-lab-safety'],
            EnrollmentStatus::Suspended,
            10
        );
    }

    private function enrollment(
        User $user,
        Course $course,
        EnrollmentStatus $status,
        float $progress,
        bool $completed = false,
        ?\DateTimeInterface $expiresAt = null,
    ): Enrollment {
        return Enrollment::query()->updateOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id],
            [
                'status' => $status,
                'progress_percent' => $progress,
                'enrolled_at' => now()->subDays(7),
                'started_at' => now()->subDays(6),
                'completed_at' => $completed ? now()->subDay() : null,
                'expires_at' => $expiresAt,
            ]
        );
    }
}
