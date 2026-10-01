<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use RuntimeException;

/**
 * Local-only historical media source (approved M2 archive extract).
 *
 * Never performs HTTP. Hash verification uses the approved sha256 manifest.
 */
final class WordPressMediaLocalSource
{
    /** @var array<string, array{size: int, sha256: string}>|null */
    private ?array $hashIndex = null;

    public function sourceRoot(): ?string
    {
        $root = config('wordpress.media.source_root');
        if (! is_string($root) || trim($root) === '') {
            return null;
        }

        return rtrim($root, '/');
    }

    public function hashManifestPath(): ?string
    {
        $configured = config('wordpress.media.hash_manifest');
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        $root = $this->sourceRoot();
        if ($root === null) {
            return null;
        }

        $candidates = [
            $root.'/media-sha256.manifest',
            dirname($root).'/manifests/media-sha256.manifest',
            dirname($root).'/media-sha256.manifest',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function allowHttp(): bool
    {
        return (bool) config('wordpress.media.allow_http', false);
    }

    /**
     * Resolve absolute path to an approved local source file.
     * Accepts archive layouts: files/{aid}/{basename} or {aid}/{basename}.
     */
    public function resolveSourceFile(int $legacyAttachmentId, string $basename): ?string
    {
        $root = $this->sourceRoot();
        if ($root === null) {
            return null;
        }

        $basename = basename(str_replace('\\', '/', $basename));
        $this->assertSafeBasename($basename);

        $candidates = [
            $root.'/files/'.$legacyAttachmentId.'/'.$basename,
            $root.'/'.$legacyAttachmentId.'/'.$basename,
        ];

        foreach ($candidates as $path) {
            $realRoot = realpath($root);
            $realFile = realpath($path);
            if ($realRoot === false || $realFile === false) {
                continue;
            }
            if (! str_starts_with($realFile, rtrim($realRoot, '/').'/')) {
                throw new RuntimeException("Source path escapes media source root: {$path}");
            }
            if (is_file($realFile)) {
                return $realFile;
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, sha256: string|null, expected_sha256: string|null, actual_bytes: int|null, code: string|null}
     */
    public function verifyFile(int $legacyAttachmentId, string $basename, string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            return [
                'ok' => false,
                'sha256' => null,
                'expected_sha256' => null,
                'actual_bytes' => null,
                'code' => 'MISSING_LOCAL_SOURCE',
            ];
        }

        $actualBytes = filesize($absolutePath) ?: 0;
        $sha = hash_file('sha256', $absolutePath) ?: null;
        $expected = $this->expectedHash($legacyAttachmentId, $basename);

        if ($expected === null) {
            return [
                'ok' => false,
                'sha256' => $sha,
                'expected_sha256' => null,
                'actual_bytes' => $actualBytes,
                'code' => 'HASH_MANIFEST_ENTRY_MISSING',
            ];
        }

        if ($expected['size'] > 0 && $expected['size'] !== $actualBytes) {
            return [
                'ok' => false,
                'sha256' => $sha,
                'expected_sha256' => $expected['sha256'],
                'actual_bytes' => $actualBytes,
                'code' => 'SIZE_MISMATCH',
            ];
        }

        if ($sha === null || ! hash_equals($expected['sha256'], $sha)) {
            return [
                'ok' => false,
                'sha256' => $sha,
                'expected_sha256' => $expected['sha256'],
                'actual_bytes' => $actualBytes,
                'code' => 'HASH_MISMATCH',
            ];
        }

        return [
            'ok' => true,
            'sha256' => $sha,
            'expected_sha256' => $expected['sha256'],
            'actual_bytes' => $actualBytes,
            'code' => null,
        ];
    }

    /**
     * @return array{size: int, sha256: string}|null
     */
    public function expectedHash(int $legacyAttachmentId, string $basename): ?array
    {
        $index = $this->hashIndex();
        $basename = basename(str_replace('\\', '/', $basename));
        $keys = [
            $legacyAttachmentId.'/'.$basename,
            'files/'.$legacyAttachmentId.'/'.$basename,
        ];
        foreach ($keys as $key) {
            if (isset($index[$key])) {
                return $index[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string, array{size: int, sha256: string}>
     */
    public function hashIndex(): array
    {
        if ($this->hashIndex !== null) {
            return $this->hashIndex;
        }

        $path = $this->hashManifestPath();
        if ($path === null || ! is_file($path)) {
            $this->hashIndex = [];

            return $this->hashIndex;
        }

        $index = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split("/\t+/", $line) ?: [];
            if (count($parts) < 3) {
                $parts = preg_split('/\s+/', $line) ?: [];
            }
            if (count($parts) < 3) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', (string) $parts[0]), '/');
            if (str_ends_with($rel, '/transfer.json') || basename($rel) === 'transfer.json') {
                continue;
            }
            $index[$rel] = [
                'size' => (int) $parts[1],
                'sha256' => strtolower((string) $parts[2]),
            ];
        }

        $this->hashIndex = $index;

        return $this->hashIndex;
    }

    public function physicalFileCountInManifest(): int
    {
        return count($this->hashIndex());
    }

    public function assertSafeRelativePath(string $relative): void
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new RuntimeException('Unsafe relative path');
        }
        if (str_starts_with($relative, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $relative) === 1) {
            throw new RuntimeException('Absolute paths rejected');
        }
        foreach (explode('/', str_replace('\\', '/', $relative)) as $part) {
            if ($part === '..') {
                throw new RuntimeException('Path traversal rejected');
            }
        }
    }

    private function assertSafeBasename(string $basename): void
    {
        if ($basename === '' || $basename === '.' || $basename === '..' || str_contains($basename, '/') || str_contains($basename, '\\')) {
            throw new RuntimeException('Unsafe basename');
        }
    }
}
