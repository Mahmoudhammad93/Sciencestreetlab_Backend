<?php
// app/Filament/Resources/TopicResource/Concerns/HasBunnyUpload.php

declare(strict_types=1);

namespace App\Filament\Resources\TopicResource\Concerns;

use App\Services\BunnyStreamService;

trait HasBunnyUpload
{
    public function getBunnyUploadCredentials(?string $title = null): array
    {
        $service = app(BunnyStreamService::class);

        $video = $service->createVideo($title ?: 'topic-'.now()->timestamp);
        $creds = $service->generateTusCredentials($video['guid']);

        return $creds;
    }

    protected function applyBunnyVideoData(array $data): array
    {
        if (($data['content_type'] ?? null) === 'video' && filled($data['bunny_video_id'] ?? null)) {
            $service = app(BunnyStreamService::class);

            $data['video_provider'] = 'bunny';
            $data['video_status'] = 'processing';
            $data['video_url'] = $service->playbackUrl($data['bunny_video_id']);
        }

        return $data;
    }
}