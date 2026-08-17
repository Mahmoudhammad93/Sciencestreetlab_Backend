<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

final class InteractiveQuestionStorageService
{
    private const DISK = 'public';

    private const ALLOWED_EXTENSIONS = [
        'html', 'htm', 'css', 'js', 'json', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'woff', 'woff2', 'ttf', 'mp3', 'mp4', 'webm',
    ];

    private const FORBIDDEN_EXTENSIONS = [
        'php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp',
    ];

    public function basePath(Question $question): string
    {
        return 'interactive-questions/'.$question->uuid;
    }

    public function absolutePath(Question $question): string
    {
        return Storage::disk(self::DISK)->path($this->basePath($question));
    }

    public function activityRelativePath(Question $question): ?string
    {
        if ($question->interactive_path) {
            return $question->interactive_path;
        }

        $candidate = $this->basePath($question).'/activity.html';

        return Storage::disk(self::DISK)->exists($candidate) ? $candidate : null;
    }

    /**
     * Store a single HTML activity file (and optional zip of assets).
     *
     * @param  array<int, UploadedFile>|null  $assets
     */
    public function storeActivity(Question $question, UploadedFile $html, ?UploadedFile $zip = null, ?array $assets = null): string
    {
        $this->assertSafeUpload($html, ['html', 'htm']);

        $dir = $this->basePath($question);
        Storage::disk(self::DISK)->makeDirectory($dir);
        Storage::disk(self::DISK)->makeDirectory($dir.'/assets');

        $htmlPath = $dir.'/activity.html';
        Storage::disk(self::DISK)->putFileAs($dir, $html, 'activity.html');

        if ($zip) {
            $this->extractZipSafely($question, $zip);
        }

        if ($assets) {
            foreach ($assets as $asset) {
                $this->storeAsset($question, $asset);
            }
        }

        $question->update([
            'interactive_path' => $htmlPath,
        ]);

        return $htmlPath;
    }

    public function storeAsset(Question $question, UploadedFile $file, ?string $subdir = 'assets'): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $this->assertAllowedExtension($ext);

        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'asset';
        $filename = $safeName.'.'.$ext;
        $targetDir = trim($this->basePath($question).'/'.trim((string) $subdir, '/'), '/');

        $this->assertPathInsideBase($question, $targetDir.'/'.$filename);

        Storage::disk(self::DISK)->makeDirectory($targetDir);
        Storage::disk(self::DISK)->putFileAs($targetDir, $file, $filename);

        return $targetDir.'/'.$filename;
    }

    public function deleteActivity(Question $question): void
    {
        $dir = $this->basePath($question);
        if (Storage::disk(self::DISK)->exists($dir)) {
            Storage::disk(self::DISK)->deleteDirectory($dir);
        }

        $question->update(['interactive_path' => null]);
    }

    public function duplicateStorage(Question $source, Question $target): void
    {
        $from = $this->basePath($source);
        $to = $this->basePath($target);

        if (! Storage::disk(self::DISK)->exists($from)) {
            return;
        }

        Storage::disk(self::DISK)->makeDirectory($to);
        File::copyDirectory(
            Storage::disk(self::DISK)->path($from),
            Storage::disk(self::DISK)->path($to)
        );

        if ($source->interactive_path) {
            $target->update([
                'interactive_path' => str_replace($from, $to, $source->interactive_path),
            ]);
        }
    }

    /**
     * Public URL for sandboxed iframe loading (via dedicated controller, not auth cookies).
     */
    public function publicActivityUrl(Question $question): ?string
    {
        return $this->signedActivityUrl($question);
    }

    /**
     * Temporary signed URL for activity.html entry point.
     */
    public function signedActivityUrl(Question $question, ?\DateTimeInterface $expiresAt = null): ?string
    {
        $path = $this->activityRelativePath($question);
        if (! $path) {
            return null;
        }

        $expires = $expiresAt ?? now()->addMinutes(60);

        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'interactive.activity',
            $expires,
            ['uuid' => $question->uuid, 'path' => 'activity.html']
        );
    }

    private function extractZipSafely(Question $question, UploadedFile $zip): void
    {
        $this->assertSafeUpload($zip, ['zip']);

        $tmp = $zip->getRealPath();
        if (! $tmp) {
            throw new DomainException('INTERACTIVE_FILE_INVALID: Unable to read zip.', 422);
        }

        $archive = new ZipArchive;
        if ($archive->open($tmp) !== true) {
            throw new DomainException('INTERACTIVE_FILE_INVALID: Corrupt zip archive.', 422);
        }

        $base = $this->absolutePath($question);

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $name = $archive->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                $archive->close();
                throw new DomainException('INTERACTIVE_FILE_INVALID: Path traversal detected in zip.', 422);
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
                $archive->close();
                throw new DomainException("INTERACTIVE_FILE_INVALID: Forbidden file type .{$ext}", 422);
            }

            if ($ext !== '' && ! in_array($ext, self::ALLOWED_EXTENSIONS, true) && $ext !== 'zip') {
                // skip unknown
                continue;
            }
        }

        $archive->extractTo($base);
        $archive->close();
    }

    private function assertSafeUpload(UploadedFile $file, array $allowedExt): void
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, $allowedExt, true)) {
            throw new DomainException('INTERACTIVE_FILE_INVALID: Extension not allowed.', 422);
        }

        $this->assertAllowedExtension($ext === 'htm' ? 'html' : $ext);
    }

    private function assertAllowedExtension(string $ext): void
    {
        if (in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new DomainException("INTERACTIVE_FILE_INVALID: Forbidden extension .{$ext}", 422);
        }

        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true) && $ext !== 'zip') {
            throw new DomainException("INTERACTIVE_FILE_INVALID: Extension .{$ext} not allowed.", 422);
        }
    }

    private function assertPathInsideBase(Question $question, string $relative): void
    {
        $base = realpath($this->absolutePath($question)) ?: $this->absolutePath($question);
        $full = Storage::disk(self::DISK)->path($relative);
        $normalized = str_replace('\\', '/', $full);
        $baseNorm = str_replace('\\', '/', $base);

        if (! str_starts_with($normalized, rtrim($baseNorm, '/').'/') && $normalized !== $baseNorm) {
            // Directory may not exist yet — validate string form
            $expectedPrefix = str_replace('\\', '/', Storage::disk(self::DISK)->path($this->basePath($question)));
            if (! str_starts_with($normalized, rtrim($expectedPrefix, '/').'/')) {
                throw new DomainException('INTERACTIVE_FILE_INVALID: Path traversal blocked.', 422);
            }
        }
    }
}
