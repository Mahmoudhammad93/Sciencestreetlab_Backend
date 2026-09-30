<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

/**
 * Normalize YouTube URL variants to a video id + canonical watch URL.
 * Does not rewrite playlists into single-video semantics beyond extracting v=.
 */
final class YouTubeVideoReferenceNormalizer
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    public function extractVideoId(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $text = $this->unescape($raw);
        $candidates = [];

        if (preg_match_all(
            '#https?://(?:www\.)?(?:youtube\.com|youtu\.be|youtube-nocookie\.com)/[^\s"\'<>\\\\]+#i',
            $text,
            $matches
        ) > 0) {
            $candidates = $matches[0];
        }

        if ($candidates === [] && preg_match('#src=["\']([^"\']+)["\']#i', $text, $m) === 1) {
            if (str_contains(strtolower($m[1]), 'youtu')) {
                $candidates[] = $m[1];
            }
        }

        if ($candidates === [] && str_contains(strtolower($text), 'youtu')) {
            $candidates[] = $text;
        }

        foreach ($candidates as $candidate) {
            $id = $this->idFromUrl($this->unescape($candidate));
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    public function canonicalWatchUrl(?string $raw): ?string
    {
        $id = $this->extractVideoId($raw);

        return $id === null ? null : 'https://www.youtube.com/watch?v='.$id;
    }

    /**
     * @param  list<string>  $rawUrls
     * @return list<string>
     */
    public function uniqueVideoIds(array $rawUrls): array
    {
        $ids = [];
        $seen = [];
        foreach ($rawUrls as $raw) {
            $id = $this->extractVideoId($raw);
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }

        return $ids;
    }

    private function idFromUrl(string $url): ?string
    {
        $url = trim($url);
        $url = rtrim($url, '\\');

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        parse_str((string) ($parts['query'] ?? ''), $query);

        $id = null;
        if (str_contains($host, 'youtu.be')) {
            $id = explode('/', ltrim($path, '/'))[0] ?? null;
        } elseif (str_contains($host, 'youtube')) {
            if (str_starts_with($path, '/watch')) {
                $id = isset($query['v']) ? (string) $query['v'] : null;
            } elseif (str_contains($path, '/embed/')) {
                $id = explode('/', explode('/embed/', $path, 2)[1])[0] ?? null;
            } elseif (str_contains($path, '/shorts/')) {
                $id = explode('/', explode('/shorts/', $path, 2)[1])[0] ?? null;
            } elseif (str_contains($path, '/v/')) {
                $id = explode('/', explode('/v/', $path, 2)[1])[0] ?? null;
            } elseif (isset($query['v'])) {
                $id = (string) $query['v'];
            }
        }

        if ($id === null || $id === '') {
            return null;
        }

        $id = explode('&', explode('?', $id)[0])[0];
        $id = str_replace(['\\u0026', '\\'], '', $id);

        return preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
    }

    private function unescape(string $value): string
    {
        $cur = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cur = str_replace(['\\/', '\/'], '/', $cur);
        // Decode common JSON unicode escapes without evaluating arbitrary sequences.
        $cur = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static function (array $m): string {
            $code = hexdec($m[1]);

            return mb_convert_encoding(pack('n', $code), 'UTF-8', 'UTF-16BE') ?: '';
        }, $cur) ?? $cur;

        return $cur;
    }
}
