<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\TopicContentType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Database\Seeders\FiveLabCoursesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FiveLabCoursesSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FiveLabCoursesSeeder::class);
    }

    public function test_seeds_five_published_school_courses(): void
    {
        $courses = Course::query()->whereIn('slug', FiveLabCoursesSeeder::COURSE_SLUGS)->get();

        $this->assertCount(5, $courses);

        foreach ($courses as $course) {
            $this->assertSame(AccessType::School, $course->access_type);
            $this->assertTrue($course->is_published);
        }
    }

    public function test_each_course_has_one_lesson_with_video_interactive_and_quiz(): void
    {
        foreach (FiveLabCoursesSeeder::COURSE_SLUGS as $slug) {
            $course = Course::query()->where('slug', $slug)->firstOrFail();
            $this->assertSame(1, $course->lessons()->count());

            $lesson = $course->lessons()->firstOrFail();
            $topics = Topic::query()->where('lesson_id', $lesson->id)->orderBy('sort_order')->get();

            $this->assertCount(2, $topics);
            $this->assertSame(TopicContentType::Video->value, $topics[0]->content_type);
            $this->assertSame(TopicContentType::Interactive->value, $topics[1]->content_type);
            $this->assertSame(FiveLabCoursesSeeder::INTERACTIVE_URL, $topics[1]->video_url);

            $this->assertSame(1, Quiz::query()
                ->where('quizable_type', $lesson::class)
                ->where('quizable_id', $lesson->id)
                ->count());
        }
    }

    public function test_each_course_has_starter_and_complete_plans_with_products(): void
    {
        foreach (FiveLabCoursesSeeder::COURSE_SLUGS as $slug) {
            $course = Course::query()->where('slug', $slug)->firstOrFail();

            $plans = CoursePlan::query()
                ->where('course_id', $course->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            $this->assertCount(2, $plans);
            $this->assertSame('Starter Plan', $plans[0]->getTranslation('name', 'en'));
            $this->assertSame(0.0, (float) $plans[0]->price);
            $this->assertSame('Complete Plan', $plans[1]->getTranslation('name', 'en'));
            $this->assertSame(79.0, (float) $plans[1]->price);

            $this->assertSame(2, Product::query()->where('course_id', $course->id)->count());
            $this->assertTrue(
                Product::query()
                    ->where('course_plan_id', $plans[1]->id)
                    ->where('price', 79)
                    ->exists()
            );
        }
    }
}
