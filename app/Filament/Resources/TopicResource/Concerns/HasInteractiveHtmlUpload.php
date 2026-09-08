<?php

declare(strict_types=1);

namespace App\Filament\Resources\TopicResource\Concerns;

use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait HasInteractiveHtmlUpload
{
    /**
     * Create/update the linked InteractiveActivity and store uploaded HTML/ZIP.
     *
     * @param  array<string, mixed>  $data
     */
    protected function syncInteractivePackage(Topic $topic, array $data): void
    {
        if (($data['content_type'] ?? $topic->content_type) !== 'interactive') {
            return;
        }

        $htmlState = $data['interactive_html_upload'] ?? null;
        $zipState = $data['interactive_zip_upload'] ?? null;

        $activity = InteractiveActivity::query()->firstOrNew(['topic_id' => $topic->id]);

        if (! $activity->exists) {
            $activity->lesson_id = $topic->lesson_id;
            $activity->activity_type = InteractiveActivityType::VirtualLab->value;
            $activity->status = InteractiveActivityStatus::Published;
            $activity->difficulty = QuestionDifficulty::Medium;
            $activity->points = 40;
            $activity->estimated_time_seconds = 600;
            $activity->version = 1;
            $activity->entry_file = 'index.html';
            $activity->title = [
                'ar' => $topic->getTranslation('title', 'ar') ?: 'نشاط تفاعلي',
                'en' => $topic->getTranslation('title', 'en') ?: 'Interactive activity',
            ];
            $activity->description = [
                'ar' => 'نشاط HTML تفاعلي مرتبط بالموضوع.',
                'en' => 'Interactive HTML activity linked to this topic.',
            ];
            $activity->instructions = [
                'ar' => 'أكمل النشاط التفاعلي.',
                'en' => 'Complete the interactive activity.',
            ];
            $activity->activity_config = [
                'topic_id' => $topic->id,
            ];
            $activity->save();
        } else {
            $activity->lesson_id = $topic->lesson_id;
            $activity->status = InteractiveActivityStatus::Published;
            $activity->save();
        }

        $uploaded = false;
        if ($this->storeInteractiveUpload($activity, $htmlState) || $this->storeInteractiveUpload($activity, $zipState)) {
            $uploaded = true;
            $activity->refresh();

            $launch = app(InteractiveActivityPackageService::class)->signedLaunchUrl($activity);
            $topic->update([
                'video_url' => $launch,
                'video_provider' => 'interactive_package',
            ]);
        }

        if ($uploaded) {
            Notification::make()
                ->title('Interactive HTML saved')
                ->body('Package stored and linked to this topic.')
                ->success()
                ->send();
        }
    }

    private function storeInteractiveUpload(InteractiveActivity $activity, mixed $state): bool
    {
        if (! $state) {
            return false;
        }

        $path = is_array($state) ? (string) ($state[0] ?? '') : (string) $state;
        if ($path === '') {
            return false;
        }

        $absolute = Storage::disk('local')->path($path);
        if (! is_file($absolute)) {
            return false;
        }

        $upload = new UploadedFile($absolute, basename($absolute), null, null, true);
        app(InteractiveActivityPackageService::class)->storeUploadedPackage(
            $activity,
            $upload,
            $activity->entry_file ?: 'index.html',
        );
        Storage::disk('local')->delete($path);

        return true;
    }
}
