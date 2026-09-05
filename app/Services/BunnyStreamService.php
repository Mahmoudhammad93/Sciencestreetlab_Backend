<?php
// app/Services/BunnyStreamService.php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BunnyStreamService
{
    protected string $libraryId;
    protected string $apiKey;
    protected string $cdnHostname;
    protected string $baseUrl;

    public function __construct()
    {
        $this->libraryId = config('services.bunny.stream.library_id');
        $this->apiKey = config('services.bunny.stream.api_key');
        $this->cdnHostname = config('services.bunny.stream.cdn_hostname');
        $this->baseUrl = "https://video.bunnycdn.com/library/{$this->libraryId}";
    }

    public function createVideo(string $title): array
    {
        $response = Http::withHeaders([
            'AccessKey' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/videos", ['title' => $title]);

        $response->throw();

        return $response->json();
    }

    public function generateTusCredentials(string $videoId, int $ttlSeconds = 3600): array
    {
        $expiration = now()->addSeconds($ttlSeconds)->timestamp;
        $signature = hash('sha256', $this->libraryId.$this->apiKey.$expiration.$videoId);

        return [
            'libraryId' => $this->libraryId,
            'videoId' => $videoId,
            'expiration' => $expiration,
            'signature' => $signature,
        ];
    }

    public function getVideo(string $videoId): array
    {
        $response = Http::withHeaders(['AccessKey' => $this->apiKey])
            ->get("{$this->baseUrl}/videos/{$videoId}");

        $response->throw();

        return $response->json();
    }

    public function deleteVideo(string $videoId): bool
    {
        return Http::withHeaders(['AccessKey' => $this->apiKey])
            ->delete("{$this->baseUrl}/videos/{$videoId}")
            ->successful();
    }

    public function playbackUrl(string $videoId): string
    {
        return "https://{$this->cdnHostname}/{$videoId}/playlist.m3u8";
    }

    public function thumbnailUrl(string $videoId): string
    {
        return "https://{$this->cdnHostname}/{$videoId}/thumbnail.jpg";
    }
}