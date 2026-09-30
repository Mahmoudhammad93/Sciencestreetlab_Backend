<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Probe remote WordPress upload URLs without persisting bodies.
 * Prefer HEAD; fall back to Range/minimal GET when HEAD is unsupported.
 */
final class WordPressMediaAvailabilityProbe
{
    public const AVAILABLE = 'AVAILABLE';

    public const MISSING_404 = 'MISSING_404';

    public const FORBIDDEN = 'FORBIDDEN';

    public const TIMEOUT = 'TIMEOUT';

    public const SERVER_ERROR = 'SERVER_ERROR';

    public const CONTENT_TYPE_MISMATCH = 'CONTENT_TYPE_MISMATCH';

    public const REDIRECT_EXTERNAL = 'REDIRECT_EXTERNAL';

    public const OTHER_FAILURE = 'OTHER_FAILURE';

    public function __construct(
        private readonly int $timeoutSeconds = 25,
    ) {}

    /**
     * Build canonical uploads URL from relative _wp_attached_file path.
     */
    public function canonicalSourceUrl(string $uploadsBase, string $relativePath): string
    {
        $base = rtrim($uploadsBase, '/').'/';
        $parts = explode('/', ltrim($relativePath, '/'));
        $encoded = array_map(static fn (string $part): string => rawurlencode($part), $parts);

        return $base.implode('/', $encoded);
    }

    /**
     * @return array{
     *   availability: string,
     *   status: int,
     *   final_url: ?string,
     *   redirect_count: int,
     *   content_type: ?string,
     *   content_length: ?int,
     *   method: ?string,
     *   error: ?string
     * }
     */
    public function probe(string $url, ?string $expectedMime = null): array
    {
        $result = $this->emptyResult();

        try {
            $response = Http::withHeaders(['User-Agent' => 'ScienceStreetLab-M1-MediaProbe/1.0'])
                ->withOptions(['allow_redirects' => ['track_redirects' => true]])
                ->timeout($this->timeoutSeconds)
                ->head($url);

            if ($this->headLooksReliable($response)) {
                return $this->fillFromResponse($result, $response, 'HEAD', $url, $expectedMime);
            }
        } catch (ConnectionException $e) {
            $result['error'] = 'HEAD:'.$e->getMessage();
            if (Str::contains(strtolower($e->getMessage()), 'timed out')) {
                $result['availability'] = self::TIMEOUT;

                return $result;
            }
        } catch (\Throwable $e) {
            $result['error'] = 'HEAD:'.$e->getMessage();
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'ScienceStreetLab-M1-MediaProbe/1.0',
                'Range' => 'bytes=0-0',
            ])
                ->withOptions(['allow_redirects' => ['track_redirects' => true]])
                ->timeout($this->timeoutSeconds)
                ->get($url);

            // Read at most one byte then discard — never persist.
            $response->body();

            return $this->fillFromResponse($result, $response, 'GET_RANGE', $url, $expectedMime);
        } catch (ConnectionException $e) {
            $result['error'] = trim(($result['error'] ?? '').'; GET:'.$e->getMessage());
            if (Str::contains(strtolower($e->getMessage()), 'timed out')) {
                $result['availability'] = self::TIMEOUT;
            }

            return $result;
        } catch (\Throwable $e) {
            $result['error'] = trim(($result['error'] ?? '').' GET:'.$e->getMessage());

            return $result;
        }
    }

    private function headLooksReliable(Response $response): bool
    {
        $status = $response->status();
        if (in_array($status, [405, 501], true)) {
            return false;
        }

        // Some CDNs return 403/400 to HEAD while GET works — treat empty body-less oddities as fallback.
        if ($status === 403 && blank($response->header('Content-Type'))) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function fillFromResponse(
        array $result,
        Response $response,
        string $method,
        string $originalUrl,
        ?string $expectedMime,
    ): array {
        $status = $response->status();
        $finalUrl = $response->effectiveUri()?->__toString() ?? $response->header('X-Guzzle-Redirect-History');
        if (is_array($finalUrl)) {
            $finalUrl = end($finalUrl) ?: null;
        }
        if (! is_string($finalUrl) || $finalUrl === '') {
            $finalUrl = $originalUrl;
        }

        $contentType = $response->header('Content-Type') ?: null;
        $contentLength = $this->parseContentLength($response);

        $redirectHistory = $response->header('X-Guzzle-Redirect-History');
        $redirectCount = is_array($redirectHistory) ? count($redirectHistory) : (is_string($redirectHistory) && $redirectHistory !== '' ? 1 : 0);
        if ($redirectCount === 0 && $finalUrl !== $originalUrl) {
            $redirectCount = 1;
        }

        $result['method'] = $method;
        $result['status'] = $status;
        $result['final_url'] = $finalUrl;
        $result['redirect_count'] = $redirectCount;
        $result['content_type'] = $contentType;
        $result['content_length'] = $contentLength;
        $result['availability'] = $this->classify($status, $contentType, $expectedMime, $originalUrl, $finalUrl);

        return $result;
    }

    private function parseContentLength(Response $response): ?int
    {
        $range = $response->header('Content-Range');
        if (is_string($range) && preg_match('#/(\d+)$#', $range, $m) === 1) {
            return (int) $m[1];
        }

        $cl = $response->header('Content-Length');
        if (is_numeric($cl)) {
            return (int) $cl;
        }

        return null;
    }

    private function classify(
        int $status,
        ?string $contentType,
        ?string $expectedMime,
        string $originalUrl,
        string $finalUrl,
    ): string {
        if ($status === 404) {
            return self::MISSING_404;
        }
        if (in_array($status, [401, 403], true)) {
            return self::FORBIDDEN;
        }
        if ($status >= 500) {
            return self::SERVER_ERROR;
        }
        if (! in_array($status, [200, 206], true)) {
            return self::OTHER_FAILURE;
        }

        $ctype = strtolower(trim(explode(';', (string) $contentType)[0]));
        if (str_starts_with($ctype, 'text/html')) {
            return self::CONTENT_TYPE_MISMATCH;
        }

        if ($expectedMime && $ctype !== '') {
            $expMain = explode('/', strtolower($expectedMime))[0] ?? '';
            $gotMain = explode('/', $ctype)[0] ?? '';
            if ($expMain !== '' && $gotMain !== '' && $expMain !== $gotMain) {
                return self::CONTENT_TYPE_MISMATCH;
            }
        }

        $origHost = parse_url($originalUrl, PHP_URL_HOST);
        $finalHost = parse_url($finalUrl, PHP_URL_HOST);
        if (is_string($origHost) && is_string($finalHost) && strcasecmp($origHost, $finalHost) !== 0) {
            // Still usable if content-type matches image — flag separately only when off-site host.
            if (! str_ends_with(strtolower($finalHost), 'sciencestreetlab.com')) {
                return self::REDIRECT_EXTERNAL;
            }
        }

        return self::AVAILABLE;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(): array
    {
        return [
            'availability' => self::OTHER_FAILURE,
            'status' => 0,
            'final_url' => null,
            'redirect_count' => 0,
            'content_type' => null,
            'content_length' => null,
            'method' => null,
            'error' => null,
        ];
    }
}
