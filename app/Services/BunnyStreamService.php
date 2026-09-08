<?php
// app/Services/BunnyStreamService.php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BunnyStreamService
{
    protected string $libraryId = '';

    protected string $apiKey = '';

    protected string $cdnHostname = '';

    protected string $baseUrl = '';

    public function __construct()
    {
        $this->libraryId = (string) (config('services.bunny.stream.library_id') ?? '');
        $this->apiKey = (string) (config('services.bunny.stream.api_key') ?? '');
        $this->cdnHostname = (string) (config('services.bunny.stream.cdn_hostname') ?? '');
        $this->baseUrl = $this->libraryId !== ''
            ? "https://video.bunnycdn.com/library/{$this->libraryId}"
            : '';
    }

    public function createVideo(string $title): array
    {
        $this->assertConfigured();

        $response = Http::withHeaders([
            'AccessKey' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/videos", ['title' => $title]);

        $response->throw();

        return $response->json();
    }

    /**
     * Create a Bunny Stream video object and upload a local file (HTTP PUT binary).
     *
     * @return array{guid: string, title: string, playback_url: string, thumbnail_url: string}
     */
    public function createAndUpload(string $title, string $absolutePath): array
    {
        $this->assertConfigured();

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new \InvalidArgumentException("Video file not readable: {$absolutePath}");
        }

        $created = $this->createVideo($title);
        $guid = (string) ($created['guid'] ?? '');

        if ($guid === '') {
            throw new \RuntimeException('Bunny Stream did not return a video guid.');
        }

        $this->uploadVideoFile($guid, $absolutePath);

        return [
            'guid' => $guid,
            'title' => (string) ($created['title'] ?? $title),
            'playback_url' => $this->playbackUrl($guid),
            'thumbnail_url' => $this->thumbnailUrl($guid),
        ];
    }

    public function uploadVideoFile(string $videoId, string $absolutePath): void
    {
        $this->assertConfigured();

        $stream = fopen($absolutePath, 'r');
        if ($stream === false) {
            throw new \InvalidArgumentException("Unable to open video file: {$absolutePath}");
        }

        try {
            $response = Http::withHeaders([
                'AccessKey' => $this->apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(600)
                ->withBody($stream, 'application/octet-stream')
                ->put("{$this->baseUrl}/videos/{$videoId}");

            $response->throw();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function isConfigured(): bool
    {
        return filled($this->libraryId) && filled($this->apiKey) && filled($this->cdnHostname);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException(
                'Bunny Stream is not configured. Set BUNNY_STREAM_LIBRARY_ID, BUNNY_STREAM_API_KEY, and BUNNY_STREAM_CDN_HOSTNAME in .env.'
            );
        }
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