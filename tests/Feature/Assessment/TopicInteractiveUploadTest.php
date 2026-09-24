<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Domain\Enums\TopicContentType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Covers the Filament Topic interactive upload storage path (HTML + ZIP)
 * without driving Livewire UI.
 */
final class TopicInteractiveUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_html_upload_links_activity_to_topic_with_valid_launch_url(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        [$topic, $activity] = $this->topicWithActivity();

        $tmp = Storage::disk('local')->path('tmp/topic-interactive-uploads/lab.html');
        Storage::disk('local')->makeDirectory('tmp/topic-interactive-uploads');
        file_put_contents($tmp, '<html><body><h1>Lab</h1></body></html>');

        $upload = new UploadedFile($tmp, 'lab.html', 'text/html', null, true);
        $path = app(InteractiveActivityPackageService::class)->storeUploadedPackage(
            $activity,
            $upload,
            'index.html',
        );

        $activity->refresh();
        $this->assertSame($topic->id, $activity->topic_id);
        $this->assertSame('index.html', $activity->entry_file);
        $this->assertStringContainsString('/v1/index.html', $path);
        Storage::disk('public')->assertExists($activity->activity_package_path);

        $launch = app(InteractiveActivityPackageService::class)->signedLaunchUrl($activity);
        $this->assertNotNull($launch);
        $this->assertStringContainsString('/interactive-activities/', $launch);

        $topic->update([
            'video_url' => $launch,
            'video_provider' => 'interactive_package',
        ]);
        $this->assertSame('interactive_package', $topic->fresh()->video_provider);
        $this->assertSame($launch, $topic->fresh()->video_url);
    }

    public function test_zip_package_upload_extracts_index_and_keeps_topic_link(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        [$topic, $activity] = $this->topicWithActivity();

        $zipRel = 'tmp/topic-interactive-uploads/pack.zip';
        Storage::disk('local')->makeDirectory('tmp/topic-interactive-uploads');
        $zipAbs = Storage::disk('local')->path($zipRel);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipAbs, ZipArchive::CREATE));
        $zip->addFromString('index.html', '<html><body>ZIP Lab</body></html>');
        $zip->addFromString('css/app.css', 'body{color:navy}');
        $zip->close();

        $upload = new UploadedFile($zipAbs, 'pack.zip', 'application/zip', null, true);
        app(InteractiveActivityPackageService::class)->storeUploadedPackage($activity, $upload);

        $activity->refresh();
        $this->assertSame($topic->id, $activity->topic_id);
        $this->assertSame('index.html', $activity->entry_file);
        Storage::disk('public')->assertExists($activity->activity_package_path);
        Storage::disk('public')->assertExists(
            dirname((string) $activity->activity_package_path).'/css/app.css'
        );

        $launch = app(InteractiveActivityPackageService::class)->signedLaunchUrl($activity);
        $this->assertNotNull($launch);
        $this->assertStringContainsString((string) $activity->uuid, $launch);
    }

    /**
     * @return array{0: Topic, 1: InteractiveActivity}
     */
    private function topicWithActivity(): array
    {
        $course = Course::query()->create([
            'slug' => 'interactive-upload-'.uniqid(),
            'access_type' => AccessType::Free,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Interactive', 'ar' => 'تفاعلي'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'lesson-'.uniqid(),
            'title' => ['en' => 'Lesson', 'ar' => 'درس'],
            'sort_order' => 1,
            'is_published' => true,
            'lesson_type' => LessonType::Theory,
        ]);
        $topic = Topic::query()->create([
            'lesson_id' => $lesson->id,
            'slug' => 'topic-'.uniqid(),
            'title' => ['en' => 'Topic', 'ar' => 'موضوع'],
            'content_type' => TopicContentType::Interactive->value,
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $activity = InteractiveActivity::query()->create([
            'lesson_id' => $lesson->id,
            'topic_id' => $topic->id,
            'activity_type' => InteractiveActivityType::VirtualLab->value,
            'status' => InteractiveActivityStatus::Published,
            'difficulty' => QuestionDifficulty::Medium,
            'points' => 10,
            'version' => 1,
            'entry_file' => 'index.html',
            'title' => ['en' => 'Act', 'ar' => 'نشاط'],
            'description' => ['en' => 'd', 'ar' => 'و'],
            'instructions' => ['en' => 'i', 'ar' => 'ت'],
            'activity_config' => ['topic_id' => $topic->id],
        ]);

        return [$topic, $activity];
    }
}
