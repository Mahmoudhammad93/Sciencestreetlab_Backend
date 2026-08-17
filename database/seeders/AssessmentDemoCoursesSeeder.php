<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionBankStatus;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Domain\Enums\QuizSelectionMode;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionTag;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Three complete STEM demo courses with question banks covering ALL types,
 * difficulty levels, tags, generated quizzes, and 3 interactive HTML activities.
 */
final class AssessmentDemoCoursesSeeder extends Seeder
{
    private const IMAGE = 'https://sciencestreetlab.com/wp-content/uploads/2026/01/download-37.jpg';

    private const VIDEOS = [
        'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
        'https://media.w3.org/2010/05/sintel/trailer.mp4',
        'https://test-videos.co.uk/vids/bigbuckbunny/mp4/h264/360/Big_Buck_Bunny_360_10s_1MB.mp4',
    ];

    public function run(): void
    {
        $template = CertificateTemplate::query()->firstOrCreate(
            ['slug' => 'default'],
            [
                'is_active' => true,
                'name' => ['ar' => 'شهادة افتراضية', 'en' => 'Default Certificate'],
                'layout_config' => ['page_size' => 'A4_landscape'],
            ]
        );

        $demo = User::query()->updateOrCreate(
            ['email' => 'demo@sciencestreetlab.com'],
            [
                'name' => 'Demo Student',
                'password' => Hash::make('password'),
                'locale' => 'ar',
                'email_verified_at' => now(),
            ]
        );

        $tags = $this->seedTags();

        $biology = $this->seedCourse($template, 'intro-biology-lab', [
            'ar' => 'مقدمة في علم الأحياء',
            'en' => 'Introduction to Biology',
        ], [
            'ar' => 'خلايا والجسم البشري مع بنوك أسئلة وأنشطة تفاعلية',
            'en' => 'Cells and human body with banks, quizzes, and interactive games',
        ], 1);

        $physics = $this->seedCourse($template, 'basic-physics-lab', [
            'ar' => 'مقدمة في الفيزياء',
            'en' => 'Introduction to Physics',
        ], [
            'ar' => 'الحركة والقوى والطاقة',
            'en' => 'Motion, forces, and energy',
        ], 2);

        $chemistry = $this->seedCourse($template, 'basic-chemistry-lab', [
            'ar' => 'أساسيات الكيمياء',
            'en' => 'Basic Chemistry',
        ], [
            'ar' => 'المادة والذرات والتفاعلات',
            'en' => 'Matter, atoms, and reactions',
        ], 3);

        $bioLessons = $this->seedLessons($biology, [
            ['slug' => 'intro-cells', 'title' => ['ar' => 'مقدمة للخلايا', 'en' => 'Introduction to Cells']],
            ['slug' => 'cell-structure', 'title' => ['ar' => 'تركيب الخلية', 'en' => 'Cell Structure']],
            ['slug' => 'human-body', 'title' => ['ar' => 'جسم الإنسان', 'en' => 'Human Body']],
        ]);

        $physLessons = $this->seedLessons($physics, [
            ['slug' => 'motion', 'title' => ['ar' => 'الحركة', 'en' => 'Motion']],
            ['slug' => 'forces', 'title' => ['ar' => 'القوى', 'en' => 'Forces']],
            ['slug' => 'light-reflection', 'title' => ['ar' => 'الضوء والانعكاس', 'en' => 'Light and Reflection']],
            ['slug' => 'energy', 'title' => ['ar' => 'الطاقة', 'en' => 'Energy']],
        ]);

        $chemLessons = $this->seedLessons($chemistry, [
            ['slug' => 'matter', 'title' => ['ar' => 'المادة', 'en' => 'Matter']],
            ['slug' => 'atoms', 'title' => ['ar' => 'الذرات', 'en' => 'Atoms']],
            ['slug' => 'reactions', 'title' => ['ar' => 'التفاعلات الكيميائية', 'en' => 'Chemical Reactions']],
        ]);

        $bioBank = $this->seedFullBank($bioLessons[1], 'biology-cell-bank', ['ar' => 'بنك خلايا', 'en' => 'Cell bank'], $tags, 'biology');
        $this->seedFullBank($bioLessons[0], 'biology-intro-bank', ['ar' => 'بنك مقدمة أحياء', 'en' => 'Bio intro bank'], $tags, 'biology');
        $bodyBank = $this->seedFullBank($bioLessons[2], 'biology-body-bank', ['ar' => 'بنك جسم الإنسان', 'en' => 'Human body bank'], $tags, 'biology');

        $physBank = $this->seedFullBank($physLessons[1], 'physics-forces-bank', ['ar' => 'بنك القوى', 'en' => 'Forces bank'], $tags, 'physics');
        $this->seedFullBank($physLessons[0], 'physics-motion-bank', ['ar' => 'بنك الحركة', 'en' => 'Motion bank'], $tags, 'physics');
        $lightBank = $this->seedFullBank($physLessons[2], 'physics-light-bank', ['ar' => 'بنك الضوء', 'en' => 'Light bank'], $tags, 'physics');
        $energyBank = $this->seedFullBank($physLessons[3], 'physics-energy-bank', ['ar' => 'بنك الطاقة', 'en' => 'Energy bank'], $tags, 'physics');

        $this->seedFullBank($chemLessons[0], 'chem-matter-bank', ['ar' => 'بنك المادة', 'en' => 'Matter bank'], $tags, 'chemistry');
        $this->seedFullBank($chemLessons[1], 'chem-atoms-bank', ['ar' => 'بنك الذرات', 'en' => 'Atoms bank'], $tags, 'chemistry');
        $chemBank = $this->seedFullBank($chemLessons[2], 'chem-reactions-bank', ['ar' => 'بنك التفاعلات', 'en' => 'Reactions bank'], $tags, 'chemistry');

        $packages = app(InteractiveActivityPackageService::class);
        $bodyGame = $this->seedActivity(
            $bioLessons[2],
            'human-body-game',
            InteractiveActivityType::DragDrop,
            ['ar' => 'لعبة جسم الإنسان', 'en' => 'Human Body Game'],
            $packages,
            base_path('resources/examples/interactive-activities/human-body-game'),
            ['expected' => ['step1' => 'heart', 'step2' => 'Heart', 'step3' => 'Lungs']]
        );
        $cellGame = $this->seedActivity(
            $bioLessons[1],
            'cell-structure-game',
            InteractiveActivityType::QuizGame,
            ['ar' => 'لعبة تركيب الخلية', 'en' => 'Cell Structure Game'],
            $packages,
            base_path('resources/examples/interactive-activities/cell-structure-game'),
            ['expected' => ['q1' => 'Nucleus', 'q2' => 'Mitochondria', 'q3' => 'Ribosome', 'q4' => 'Cell membrane']]
        );
        $physSim = $this->seedActivity(
            $physLessons[1],
            'physics-force-sim',
            InteractiveActivityType::Simulation,
            ['ar' => 'محاكاة القوة والتسارع', 'en' => 'Physics Force Simulation'],
            $packages,
            base_path('resources/examples/interactive-activities/physics-force-sim'),
            []
        );
        $lightLab = $this->seedActivity(
            $physLessons[2],
            'light-lab',
            InteractiveActivityType::VirtualLab,
            ['ar' => 'مختبر الضوء - شارع العلوم', 'en' => 'Light Lab — Science Street'],
            $packages,
            base_path('resources/examples/interactive-activities/light-lab'),
            [],
            [
                'estimated_time_seconds' => 900,
                'points' => 50,
                'description' => [
                    'ar' => 'نشاط HTML تفاعلي كامل: محاكاة Canvas، 5 تحديات، ظلال، انعكاس، عدسة، ترمومتر.',
                    'en' => 'Complete opaque HTML activity: Canvas simulation, 5 challenges, shadows, reflection, lens, thermometer.',
                ],
                'instructions' => [
                    'ar' => 'اسحب مصادر الضوء والترمومتر، جرّب السيناريوهات، وأكمل التحديات الخمس.',
                    'en' => 'Drag light sources and the thermometer, try scenarios, complete all 5 challenges.',
                ],
            ]
        );

        $plantGrowth = $this->seedActivity(
            $bioLessons[0],
            'plant-growth',
            InteractiveActivityType::Simulation,
            ['ar' => 'رحلة نمو البذور', 'en' => 'Seed Growth Journey'],
            $packages,
            base_path('resources/examples/interactive-activities/plant-growth'),
            [],
            [
                'estimated_time_seconds' => 600,
                'points' => 40,
                'description' => [
                    'ar' => 'نشاط تفاعلي كامل عن نمو البذور: ري، ضوء، تربة، وتحديات علمية.',
                    'en' => 'Complete interactive activity on seed growth: watering, light, soil, and science challenges.',
                ],
                'instructions' => [
                    'ar' => 'اتبع رحلة البذرة، جرّب شروط النمو، وأكمل التحديات.',
                    'en' => 'Follow the seed journey, try growth conditions, and complete the challenges.',
                ],
            ]
        );

        $soundLab = $this->seedActivity(
            $physLessons[3],
            'sound-lab',
            InteractiveActivityType::VirtualLab,
            ['ar' => 'مختبر خصائص الصوت', 'en' => 'Sound Properties Lab'],
            $packages,
            base_path('resources/examples/interactive-activities/sound-lab'),
            [],
            [
                'estimated_time_seconds' => 720,
                'points' => 50,
                'description' => [
                    'ar' => 'مختبر صوت تفاعلي: اهتزاز، شدة، انتقال الموجات، 5 تحديات.',
                    'en' => 'Interactive sound lab: vibration, intensity, wave transfer, 5 challenges.',
                ],
                'instructions' => [
                    'ar' => 'جرّب تجارب الصوت في المختبر وأجب عن التحديات الخمس.',
                    'en' => 'Try sound experiments in the lab and complete all 5 challenges.',
                ],
            ]
        );

        $rubberCastle = $this->seedActivity(
            $physLessons[1],
            'rubber-castle',
            InteractiveActivityType::Simulation,
            ['ar' => 'تحدي هدم القلعة', 'en' => 'Castle Demolition Challenge'],
            $packages,
            base_path('resources/examples/interactive-activities/rubber-castle'),
            [],
            [
                'estimated_time_seconds' => 600,
                'points' => 40,
                'description' => [
                    'ar' => 'محاكاة منجنيق وطاقة الوضع المرنة: لف الاستيك، إطلاق، وتحديات.',
                    'en' => 'Catapult elastic potential energy simulation: wind rubber bands, launch, and challenges.',
                ],
                'instructions' => [
                    'ar' => 'لف الاستيك، أطلق الكرة، هدم القلعة، وأكمل الأسئلة.',
                    'en' => 'Wind the rubber bands, launch, demolish the castle, and answer the questions.',
                ],
            ]
        );

        $rubberRace = $this->seedActivity(
            $physLessons[1],
            'rubber-race',
            InteractiveActivityType::Simulation,
            ['ar' => 'سباق العربية', 'en' => 'Rubber Band Car Race'],
            $packages,
            base_path('resources/examples/interactive-activities/rubber-race'),
            [],
            [
                'estimated_time_seconds' => 480,
                'points' => 30,
                'description' => [
                    'ar' => 'سباق عربية بالاستيك: طاقة مرنة، مسافة، وتحديات تفاعلية.',
                    'en' => 'Rubber band car race: elastic energy, distance, and interactive challenges.',
                ],
                'instructions' => [
                    'ar' => 'شد الاستيك، شغّل العربية، وحقّق أفضل مسافة.',
                    'en' => 'Stretch the rubber band, run the car, and achieve the best distance.',
                ],
            ]
        );

        // Fixed quiz
        $fixed = $this->seedFixedQuiz($bioLessons[0], $bioBank, ['ar' => 'اختبار الأحياء السريع', 'en' => 'Biology Quick Quiz'], 5, 'bio-quick');
        // Generated quiz with difficulty distribution
        $generated = $this->seedGeneratedQuiz($bioLessons[1], $bioBank, [
            'ar' => 'اختبار نهائي أحياء',
            'en' => 'Biology Final Assessment',
        ], ['total_questions' => 10, 'difficulty' => ['easy' => 4, 'medium' => 4, 'hard' => 2]]);
        // Mixed quiz: standard + activities
        $mixed = $this->seedFixedQuiz($bioLessons[2], $bodyBank, [
            'ar' => 'تقييم مختلط — جسم الإنسان',
            'en' => 'Mixed Assessment — Human Body',
        ], 6, 'bio-body-mixed');
        $mixed->interactiveActivities()->sync([
            $bodyGame->id => ['sort_order' => 1, 'points' => 10],
            $cellGame->id => ['sort_order' => 2, 'points' => 10],
        ]);

        $mixedBioIntro = $this->seedFixedQuiz($bioLessons[0], $bioBank, [
            'ar' => 'تقييم مختلط — نمو النباتات',
            'en' => 'Mixed Assessment — Plant Growth',
        ], 4, 'bio-plant-mixed');
        $mixedBioIntro->interactiveActivities()->sync([
            $plantGrowth->id => ['sort_order' => 1, 'points' => 40],
        ]);

        $this->seedGeneratedQuiz($physLessons[1], $physBank, [
            'ar' => 'اختبار القوى المولّد',
            'en' => 'Generated Forces Quiz',
        ], ['total_questions' => 8, 'difficulty' => ['easy' => 3, 'medium' => 3, 'hard' => 2]]);

        $mixedForces = $this->seedFixedQuiz($physLessons[1], $physBank, [
            'ar' => 'تقييم مختلط — القوى والطاقة المرنة',
            'en' => 'Mixed Assessment — Elastic Forces',
        ], 3, 'phys-forces-mixed');
        $mixedForces->interactiveActivities()->sync([
            $rubberCastle->id => ['sort_order' => 1, 'points' => 40],
            $rubberRace->id => ['sort_order' => 2, 'points' => 30],
            $physSim->id => ['sort_order' => 3, 'points' => 10],
        ]);

        $mixedPhysics = $this->seedFixedQuiz($physLessons[2], $lightBank, [
            'ar' => 'تقييم مختلط — الضوء',
            'en' => 'Mixed Assessment — Light',
        ], 3, 'phys-light-mixed');
        $mixedPhysics->interactiveActivities()->sync([
            $lightLab->id => ['sort_order' => 1, 'points' => 50],
        ]);

        $mixedEnergy = $this->seedFixedQuiz($physLessons[3], $energyBank, [
            'ar' => 'تقييم مختلط — الصوت والطاقة',
            'en' => 'Mixed Assessment — Sound & Energy',
        ], 3, 'phys-energy-mixed');
        $mixedEnergy->interactiveActivities()->sync([
            $soundLab->id => ['sort_order' => 1, 'points' => 50],
        ]);

        $this->seedFixedQuiz($chemLessons[2], $chemBank, [
            'ar' => 'اختبار التفاعلات',
            'en' => 'Reactions Quiz',
        ], 6, 'chem-reactions');

        foreach ([$biology, $physics, $chemistry] as $course) {
            Enrollment::query()->updateOrCreate(
                ['user_id' => $demo->id, 'course_id' => $course->id],
                [
                    'status' => EnrollmentStatus::Active,
                    'progress_percent' => 0,
                    'enrolled_at' => now(),
                    'started_at' => now(),
                ]
            );
        }

        $this->command?->info('Assessment demo courses: intro-biology-lab, basic-physics-lab, basic-chemistry-lab');
        $this->command?->info(sprintf(
            'Activities: #%d body, #%d cell, #%d force-sim, #%d light, #%d plant, #%d sound, #%d castle, #%d race',
            $bodyGame->id,
            $cellGame->id,
            $physSim->id,
            $lightLab->id,
            $plantGrowth->id,
            $soundLab->id,
            $rubberCastle->id,
            $rubberRace->id,
        ));
        unset($fixed, $generated, $mixed);
    }

    /**
     * @return array<string, QuestionTag>
     */
    private function seedTags(): array
    {
        $defs = [
            'biology' => ['ar' => 'أحياء', 'en' => 'Biology'],
            'cells' => ['ar' => 'خلايا', 'en' => 'Cells'],
            'microscope' => ['ar' => 'ميكروسكوب', 'en' => 'Microscope'],
            'physics' => ['ar' => 'فيزياء', 'en' => 'Physics'],
            'chemistry' => ['ar' => 'كيمياء', 'en' => 'Chemistry'],
            'grade-7' => ['ar' => 'صف 7', 'en' => 'Grade 7'],
            'chapter-1' => ['ar' => 'فصل 1', 'en' => 'Chapter 1'],
        ];

        $out = [];
        foreach ($defs as $slug => $name) {
            $out[$slug] = QuestionTag::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name]
            );
        }

        return $out;
    }

    /**
     * @param  array{ar:string,en:string}  $title
     * @param  array{ar:string,en:string}  $desc
     */
    private function seedCourse(CertificateTemplate $template, string $slug, array $title, array $desc, int $sort): Course
    {
        return Course::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'access_type' => AccessType::Free,
                'is_published' => true,
                'published_at' => now(),
                'estimated_hours' => 4,
                'sort_order' => $sort,
                'image_url' => self::IMAGE,
                'certificate_template_id' => $template->id,
                'title' => $title,
                'short_description' => $desc,
                'description' => $desc,
            ]
        );
    }

    /**
     * @param  list<array{slug:string,title:array{ar:string,en:string}}>  $defs
     * @return list<Lesson>
     */
    private function seedLessons(Course $course, array $defs): array
    {
        $lessons = [];
        foreach ($defs as $i => $def) {
            $lesson = Lesson::query()->updateOrCreate(
                ['course_id' => $course->id, 'slug' => $def['slug']],
                [
                    'lesson_type' => LessonType::Theory->value,
                    'sort_order' => $i + 1,
                    'is_published' => true,
                    'title' => $def['title'],
                    'content' => [
                        'ar' => 'محتوى تعليمي تجريبي',
                        'en' => 'Demo lesson content for assessment labs.',
                    ],
                ]
            );
            Topic::query()->updateOrCreate(
                ['lesson_id' => $lesson->id, 'slug' => $def['slug'].'-video'],
                [
                    'sort_order' => 1,
                    'content_type' => 'video',
                    'video_url' => self::VIDEOS[$i % count(self::VIDEOS)],
                    'video_provider' => 'external',
                    'is_published' => true,
                    'title' => $def['title'],
                ]
            );
            $lessons[] = $lesson;
        }

        return $lessons;
    }

    /**
     * @param  array{ar:string,en:string}  $title
     * @param  array<string, QuestionTag>  $tags
     */
    private function seedFullBank(Lesson $lesson, string $key, array $title, array $tags, string $subject): QuestionBank
    {
        $bank = QuestionBank::query()->updateOrCreate(
            ['lesson_id' => $lesson->id],
            [
                'status' => QuestionBankStatus::Active,
                'title' => $title,
                'description' => [
                    'ar' => 'أسئلة بكل الأنواع والصعوبات',
                    'en' => 'Questions of all types and difficulties',
                ],
            ]
        );

        // Clear options for re-seed stability on this bank
        Question::query()->where('question_bank_id', $bank->id)->each(function (Question $q): void {
            $q->options()->delete();
        });

        $sort = 0;
        foreach ([QuestionDifficulty::Easy, QuestionDifficulty::Medium, QuestionDifficulty::Hard] as $diff) {
            $sort = $this->addTypedSet($bank, $sort, $diff, $subject, $tags);
        }

        Question::query()
            ->where('question_bank_id', $bank->id)
            ->where('sort_order', '>', $sort)
            ->delete();

        return $bank;
    }

    /**
     * @param  array<string, QuestionTag>  $tags
     */
    private function addTypedSet(
        QuestionBank $bank,
        int $sort,
        QuestionDifficulty $diff,
        string $subject,
        array $tags,
    ): int {
        $suffix = $diff->value;

        $q = $this->q($bank, ++$sort, QuestionType::SingleChoice, $diff, "[{$suffix}] Unit of current?", 1);
        $this->opts($q, [['Volt', false], ['Ampere', true], ['Watt', false]]);
        $this->tag($q, $tags, [$subject, 'grade-7', 'chapter-1']);

        $q = $this->q($bank, ++$sort, QuestionType::MultipleChoice, $diff, "[{$suffix}] Select safety gear", 2);
        $this->opts($q, [['Goggles', true], ['Gloves', true], ['Phone', false]]);
        $this->tag($q, $tags, [$subject, 'grade-7']);

        $q = $this->q($bank, ++$sort, QuestionType::TrueFalse, $diff, "[{$suffix}] Water is a polar solvent.", 1);
        $this->opts($q, [['True', true], ['False', false]]);
        $this->tag($q, $tags, [$subject]);

        $this->q($bank, ++$sort, QuestionType::ShortAnswer, $diff, "[{$suffix}] Capital of Egypt?", 1, [
            'answer_key' => ['accepted' => ['Cairo', 'cairo', 'القاهرة']],
        ]);
        $this->q($bank, ++$sort, QuestionType::LongAnswer, $diff, "[{$suffix}] Explain why microscopes matter.", 3, [
            'answer_key' => ['manual' => true],
        ]);
        $this->q($bank, ++$sort, QuestionType::FillBlank, $diff, "[{$suffix}] The cell is the basic unit of ___.", 1, [
            'answer_key' => ['accepted' => ['life', 'الحياة']],
        ]);

        $q = $this->q($bank, ++$sort, QuestionType::Matching, $diff, "[{$suffix}] Match organelle → function", 2);
        $this->opts($q, [
            ['Nucleus', false, ['side' => 'left', 'match_key' => 'dna']],
            ['Mitochondria', false, ['side' => 'left', 'match_key' => 'energy']],
            ['Contains DNA', false, ['side' => 'right', 'match_key' => 'dna']],
            ['Produces energy', false, ['side' => 'right', 'match_key' => 'energy']],
        ]);

        $q = $this->q($bank, ++$sort, QuestionType::Ordering, $diff, "[{$suffix}] Order the lab steps", 2);
        $this->opts($q, [
            ['Safety gear', false],
            ['Prepare tools', false],
            ['Run experiment', false],
            ['Record results', false],
        ]);

        $this->q($bank, ++$sort, QuestionType::Numeric, $diff, "[{$suffix}] What is 7 + 5?", 1, [
            'answer_key' => ['value' => 12, 'tolerance' => 0],
        ]);

        return $sort;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function q(
        QuestionBank $bank,
        int $sort,
        QuestionType $type,
        QuestionDifficulty $diff,
        string $en,
        float $points,
        array $extra = [],
    ): Question {
        return Question::query()->updateOrCreate(
            ['question_bank_id' => $bank->id, 'sort_order' => $sort],
            array_merge([
                'quiz_id' => null,
                'question_type' => $type,
                'difficulty' => $diff,
                'status' => QuestionStatus::Published,
                'points' => $points,
                'body' => ['ar' => $en, 'en' => $en],
                'explanation' => ['ar' => 'شرح', 'en' => 'Explanation'],
            ], $extra)
        );
    }

    /**
     * @param  list<array{0:string,1:bool,2?:array<string,mixed>}>  $rows
     */
    private function opts(Question $question, array $rows): void
    {
        foreach ($rows as $i => $row) {
            QuestionOption::query()->updateOrCreate(
                ['question_id' => $question->id, 'sort_order' => $i + 1],
                [
                    'is_correct' => $row[1],
                    'label' => ['ar' => $row[0], 'en' => $row[0]],
                    'meta' => $row[2] ?? null,
                ]
            );
        }
    }

    /**
     * @param  array<string, QuestionTag>  $tags
     * @param  list<string>  $slugs
     */
    private function tag(Question $question, array $tags, array $slugs): void
    {
        $ids = [];
        foreach ($slugs as $slug) {
            if (isset($tags[$slug])) {
                $ids[] = $tags[$slug]->id;
            }
        }
        if ($ids !== []) {
            $question->tags()->syncWithoutDetaching($ids);
        }
    }

    /**
     * @param  array{ar:string,en:string}  $title
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $overrides
     */
    private function seedActivity(
        Lesson $lesson,
        string $folderKey,
        InteractiveActivityType $type,
        array $title,
        InteractiveActivityPackageService $packages,
        string $sourceDir,
        array $config,
        array $overrides = [],
    ): InteractiveActivity {
        $activity = InteractiveActivity::query()
            ->where('lesson_id', $lesson->id)
            ->where('activity_config->demo_key', $folderKey)
            ->first();

        $configWithKey = array_merge($config, ['demo_key' => $folderKey]);

        $defaults = [
            'lesson_id' => $lesson->id,
            'activity_type' => $type->value,
            'status' => InteractiveActivityStatus::Published,
            'difficulty' => QuestionDifficulty::Medium,
            'points' => 10,
            'estimated_time_seconds' => 300,
            'version' => 1,
            'entry_file' => 'index.html',
            'activity_config' => $configWithKey,
            'title' => $title,
            'description' => [
                'ar' => 'نشاط تفاعلي HTML متعدد التحديات',
                'en' => 'Multi-challenge interactive HTML activity',
            ],
            'instructions' => [
                'ar' => 'ابدأ النشاط وأكمل جميع الخطوات. المنصة تعرض النشاط داخل iframe فقط.',
                'en' => 'Start the activity and complete all challenges. The platform hosts the HTML in an iframe only.',
            ],
        ];

        $payload = array_merge($defaults, $overrides);

        if (! $activity) {
            $activity = InteractiveActivity::query()->create($payload);
        } else {
            $activity->update($payload);
        }

        $packages->storeFromDirectory($activity, $sourceDir, 'index.html');

        return $activity->fresh();
    }

    /**
     * @param  array{ar:string,en:string}  $title
     */
    private function seedFixedQuiz(
        Lesson $lesson,
        QuestionBank $bank,
        array $title,
        int $count,
        ?string $quizKey = null,
    ): Quiz {
        $query = Quiz::query()
            ->where('quizable_type', Lesson::class)
            ->where('quizable_id', $lesson->id)
            ->where('selection_mode', QuizSelectionMode::Fixed);

        if ($quizKey !== null) {
            $quiz = (clone $query)->where('selection_config->demo_key', $quizKey)->first();
        } else {
            $quiz = $query->first();
        }

        $payload = [
            'passing_score' => 50,
            'max_attempts' => 10,
            'is_required' => false,
            'selection_mode' => QuizSelectionMode::Fixed,
            'shuffle_questions' => false,
            'title' => $title,
            'instructions' => [
                'ar' => 'أجب عن الأسئلة',
                'en' => 'Answer the questions',
            ],
            'selection_config' => $quizKey !== null ? ['demo_key' => $quizKey] : null,
        ];

        if (! $quiz) {
            $quiz = Quiz::query()->create(array_merge($payload, [
                'quizable_type' => Lesson::class,
                'quizable_id' => $lesson->id,
            ]));
        } else {
            $quiz->update($payload);
        }

        $quiz->questionBanks()->syncWithoutDetaching([$bank->id]);

        $questions = Question::query()
            ->where('question_bank_id', $bank->id)
            ->where('status', QuestionStatus::Published)
            ->orderBy('sort_order')
            ->limit($count)
            ->get();

        foreach ($questions as $i => $question) {
            $question->update(['quiz_id' => $quiz->id, 'sort_order' => $i + 1]);
        }

        return $quiz;
    }

    /**
     * @param  array{ar:string,en:string}  $title
     * @param  array<string, mixed>  $config
     */
    private function seedGeneratedQuiz(Lesson $lesson, QuestionBank $bank, array $title, array $config): Quiz
    {
        // Separate quiz row via unique title approach: create if missing by instructions key
        $quiz = Quiz::query()
            ->where('quizable_type', Lesson::class)
            ->where('quizable_id', $lesson->id)
            ->where('selection_mode', QuizSelectionMode::Generated)
            ->first();

        if (! $quiz) {
            $quiz = Quiz::query()->create([
                'quizable_type' => Lesson::class,
                'quizable_id' => $lesson->id,
                'passing_score' => 60,
                'max_attempts' => 5,
                'is_required' => true,
                'selection_mode' => QuizSelectionMode::Generated,
                'selection_config' => $config,
                'shuffle_questions' => true,
                'title' => $title,
                'instructions' => [
                    'ar' => 'اختبار مولّد عشوائياً حسب الصعوبة',
                    'en' => 'Random generated quiz by difficulty',
                ],
            ]);
        } else {
            $quiz->update([
                'selection_config' => $config,
                'title' => $title,
                'passing_score' => 60,
                'max_attempts' => 5,
                'is_required' => true,
                'shuffle_questions' => true,
            ]);
        }

        $quiz->questionBanks()->syncWithoutDetaching([$bank->id]);

        return $quiz;
    }
}
