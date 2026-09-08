<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Learning\Domain\Enums\TopicContentType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Database\Seeders\ThreeStationsCourseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ThreeStationsCourseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ThreeStationsCourseSeeder::class);
    }

    public function test_course_has_three_stations_each_with_video_and_interactive(): void
    {
        $course = Course::query()->where('slug', ThreeStationsCourseSeeder::COURSE_SLUG)->firstOrFail();

        $this->assertTrue($course->is_published);
        $this->assertSame(3, $course->lessons()->count());

        foreach ($course->lessons as $lesson) {
            $topics = Topic::query()->where('lesson_id', $lesson->id)->orderBy('sort_order')->get();
            $this->assertCount(2, $topics);
            $this->assertSame(TopicContentType::Video->value, $topics[0]->content_type);
            $this->assertSame(TopicContentType::Interactive->value, $topics[1]->content_type);

            $activity = InteractiveActivity::query()
                ->where('topic_id', $topics[1]->id)
                ->first();

            $this->assertNotNull($activity);
            $this->assertNotNull($activity->activity_package_path);
            $this->assertTrue(Storage::disk('public')->exists($activity->activity_package_path));
        }

        $this->assertSame(2, $course->plans()->where('is_active', true)->count());
    }
}
