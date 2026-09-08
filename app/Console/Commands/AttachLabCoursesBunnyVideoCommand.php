<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Learning\Domain\Enums\TopicContentType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use App\Services\BunnyStreamService;
use Database\Seeders\FiveLabCoursesSeeder;
use Illuminate\Console\Command;

/**
 * Upload one local MP4 to Bunny Stream and attach it to all five lab course video topics.
 *
 * Example:
 * php artisan bunny:attach-lab-video "/path/to/video.mp4"
 */
final class AttachLabCoursesBunnyVideoCommand extends Command
{
    protected $signature = 'bunny:attach-lab-video
                            {path : Absolute path to the MP4 file}
                            {--title=Science Street Lab — Atom Lesson Video : Bunny Stream video title}
                            {--reuse= : Existing Bunny video guid (skip upload)}';

    protected $description = 'Upload a video to Bunny Stream and attach it to the five lab courses';

    public function handle(BunnyStreamService $bunny): int
    {
        if (! $bunny->isConfigured() && ! filled($this->option('reuse'))) {
            $this->error('Bunny Stream credentials are missing.');
            $this->line('Add these to backend/.env then re-run:');
            $this->line('  BUNNY_STREAM_LIBRARY_ID=...');
            $this->line('  BUNNY_STREAM_API_KEY=...');
            $this->line('  BUNNY_STREAM_CDN_HOSTNAME=vz-xxxxx.b-cdn.net');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        $reuse = $this->option('reuse');

        if (filled($reuse)) {
            $guid = (string) $reuse;
            $playback = $bunny->playbackUrl($guid);
            $this->info("Reusing Bunny video {$guid}");
        } else {
            $this->info('Uploading to Bunny Stream (this may take a few minutes)...');
            $uploaded = $bunny->createAndUpload((string) $this->option('title'), $path);
            $guid = $uploaded['guid'];
            $playback = $uploaded['playback_url'];
            $this->info("Uploaded: {$guid}");
            $this->line("Playback: {$playback}");
        }

        $updated = 0;

        foreach (FiveLabCoursesSeeder::COURSE_SLUGS as $slug) {
            $course = Course::query()->where('slug', $slug)->first();
            if (! $course) {
                $this->warn("Course missing: {$slug} (run FiveLabCoursesSeeder first)");
                continue;
            }

            $lessonIds = $course->lessons()->pluck('id');
            $topics = Topic::query()
                ->whereIn('lesson_id', $lessonIds)
                ->where('content_type', TopicContentType::Video->value)
                ->get();

            foreach ($topics as $topic) {
                $topic->update([
                    'bunny_video_id' => $guid,
                    'video_provider' => 'bunny',
                    'video_status' => 'processing',
                    'video_url' => $playback,
                    'is_published' => true,
                ]);
                $updated++;
                $this->line("  ✓ {$slug} → topic #{$topic->id}");
            }
        }

        $this->info("Attached Bunny video to {$updated} topic(s).");

        return self::SUCCESS;
    }
}
