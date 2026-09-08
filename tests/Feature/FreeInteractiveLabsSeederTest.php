<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\TopicContentType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Database\Seeders\FreeInteractiveLabsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FreeInteractiveLabsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FreeInteractiveLabsSeeder::class);
    }

    public function test_free_course_has_three_interactive_only_topics(): void
    {
        $course = Course::query()->where('slug', FreeInteractiveLabsSeeder::COURSE_SLUG)->firstOrFail();

        $this->assertSame(AccessType::Free, $course->access_type);
        $this->assertTrue($course->is_published);
        $this->assertSame(1, $course->lessons()->count());

        $lesson = $course->lessons()->firstOrFail();
        $topics = Topic::query()->where('lesson_id', $lesson->id)->orderBy('sort_order')->get();

        $this->assertCount(3, $topics);

        foreach ($topics as $topic) {
            $this->assertSame(TopicContentType::Interactive->value, $topic->content_type);

            $activity = InteractiveActivity::query()->where('topic_id', $topic->id)->first();
            $this->assertNotNull($activity);
            $this->assertNotNull($activity->activity_package_path);
            $this->assertTrue(Storage::disk('public')->exists($activity->activity_package_path));
        }

        $freePlan = $course->plans()->where('is_active', true)->where('price', 0)->first();
        $this->assertNotNull($freePlan);
        $this->assertSame('Free Plan', $freePlan->getTranslation('name', 'en'));
    }
}
