<?php

declare(strict_types=1);

namespace Tests\Feature\Learning;

use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Database\Seeders\EnsureAllTopicTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnsureAllTopicTypesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_lesson_receives_video_text_and_pdf_topics(): void
    {
        $course = Course::query()->create([
            'slug' => 'topic-types-lab',
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Types lab', 'ar' => 'مختبر الأنواع'],
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'lesson-1',
            'lesson_type' => 'theory',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'Lesson', 'ar' => 'درس'],
        ]);

        (new EnsureAllTopicTypesSeeder())->ensureTypes($lesson->fresh());

        $types = $lesson->topics()->pluck('content_type')->all();
        $this->assertContains('video', $types);
        $this->assertContains('text', $types);
        $this->assertContains('pdf', $types);
        $this->assertNotNull($lesson->topics()->where('content_type', 'text')->value('content'));
        $this->assertNotNull($lesson->topics()->where('content_type', 'pdf')->value('video_url'));
    }

    public function test_clones_html_lab_onto_another_course(): void
    {
        $sourceCourse = Course::query()->create([
            'slug' => 'source-lab-course',
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Source', 'ar' => 'مصدر'],
        ]);
        $sourceLesson = Lesson::query()->create([
            'course_id' => $sourceCourse->id,
            'slug' => 'source-lesson',
            'lesson_type' => 'theory',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'Source lesson', 'ar' => 'درس'],
        ]);
        $source = \App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity::query()->create([
            'lesson_id' => $sourceLesson->id,
            'status' => \App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus::Published,
            'activity_type' => 'virtual_lab',
            'points' => 10,
            'version' => 1,
            'entry_file' => 'index.html',
            'activity_package_path' => 'interactive-activities/test/v1/index.html',
            'activity_config' => ['demo_key' => 'light-lab'],
            'title' => ['en' => 'Light Lab', 'ar' => 'مختبر الضوء'],
        ]);
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put(
            'interactive-activities/'.$source->uuid.'/v1/index.html',
            '<html><body>lab</body></html>'
        );

        $targetCourse = Course::query()->create([
            'slug' => 'intro-to-science',
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Intro', 'ar' => 'مقدمة'],
        ]);
        $targetLesson = Lesson::query()->create([
            'course_id' => $targetCourse->id,
            'slug' => 'welcome',
            'lesson_type' => 'theory',
            'sort_order' => 1,
            'is_published' => true,
            'title' => ['en' => 'Welcome', 'ar' => 'مرحبا'],
        ]);

        (new EnsureAllTopicTypesSeeder())->run();

        $this->assertTrue(
            \App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity::query()
                ->where('lesson_id', $targetLesson->id)
                ->where('activity_config->demo_key', 'light-lab')
                ->exists()
        );
        $this->assertTrue(
            $targetLesson->topics()->where('content_type', 'interactive')->exists()
        );
    }
}
