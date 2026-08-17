<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Stores versioned interactive activity packages under:
 * interactive-activities/{uuid}/v{n}/
 *
 * Uploaded HTML/JS is untrusted — served only via signed sandboxed routes.
 */
final class InteractiveActivityPackageService
{
    private const DISK = 'public';

    private const ALLOWED_EXTENSIONS = [
        'html', 'htm', 'css', 'js', 'json', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp',
        'woff', 'woff2', 'ttf', 'mp3', 'mp4', 'webm', 'wav', 'ogg',
    ];

    private const FORBIDDEN_EXTENSIONS = [
        'php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp',
    ];

    public function basePath(InteractiveActivity $activity, ?int $version = null): string
    {
        $v = $version ?? max(1, (int) $activity->version);

        return 'interactive-activities/'.$activity->uuid.'/v'.$v;
    }

    public function absolutePath(InteractiveActivity $activity, ?int $version = null): string
    {
        return Storage::disk(self::DISK)->path($this->basePath($activity, $version));
    }

    public function entryRelativePath(InteractiveActivity $activity): ?string
    {
        if ($activity->activity_package_path) {
            return $activity->activity_package_path;
        }

        $entry = $this->basePath($activity).'/'.ltrim($activity->entry_file ?: 'index.html', '/');

        return Storage::disk(self::DISK)->exists($entry) ? $entry : null;
    }

    /**
     * Store a single HTML file as index.html inside a new version directory.
     */
    public function storeHtmlFile(InteractiveActivity $activity, UploadedFile $html): string
    {
        $this->assertSafeUpload($html, ['html', 'htm']);

        $contents = (string) file_get_contents($html->getRealPath() ?: $html->getPathname());
        if ($contents === '' || ! str_contains(mb_strtolower($contents), '<html')) {
            throw new DomainException('INTERACTIVE_FILE_INVALID: Not a valid HTML document.', 422);
        }

        $nextVersion = $this->nextVersion($activity);
        $dir = $this->basePath($activity, $nextVersion);
        Storage::disk(self::DISK)->makeDirectory($dir);

        $relative = $dir.'/index.html';
        Storage::disk(self::DISK)->put($relative, $contents);

        $activity->update([
            'version' => $nextVersion,
            'entry_file' => 'index.html',
            'activity_package_path' => $relative,
        ]);

        return $relative;
    }

    public function storeUploadedPackage(InteractiveActivity $activity, UploadedFile $file, ?string $entryFile = null): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['html', 'htm'], true)) {
            return $this->storeHtmlFile($activity, $file);
        }

        return $this->storeZipPackage($activity, $file, $entryFile);
    }

    /**
     * Extract a ZIP package into a new version directory.
     */
    public function storeZipPackage(InteractiveActivity $activity, UploadedFile $zip, ?string $entryFile = null): string
    {
        $this->assertSafeUpload($zip, ['zip']);

        $nextVersion = $this->nextVersion($activity);

        $dir = $this->basePath($activity, $nextVersion);
        Storage::disk(self::DISK)->makeDirectory($dir);

        $this->extractZipSafely($activity, $zip, $nextVersion);

        $entry = $entryFile ?: $this->detectEntryFile($activity, $nextVersion);
        $relative = $dir.'/'.$entry;

        if (! Storage::disk(self::DISK)->exists($relative)) {
            throw new DomainException('INTERACTIVE_FILE_INVALID: Entry file not found in package ('.$entry.').', 422);
        }

        $activity->update([
            'version' => $nextVersion,
            'entry_file' => $entry,
            'activity_package_path' => $relative,
        ]);

        return $relative;
    }

    /**
     * Copy a local directory tree (seeders / demos) into a versioned package.
     */
    public function storeFromDirectory(InteractiveActivity $activity, string $sourceDir, string $entryFile = 'index.html'): string
    {
        if (! is_dir($sourceDir)) {
            throw new DomainException('INTERACTIVE_FILE_INVALID: Source directory missing.', 422);
        }

        $version = max(1, (int) $activity->version);
        $dir = $this->basePath($activity, $version);
        $abs = $this->absolutePath($activity, $version);

        if (is_dir($abs)) {
            File::deleteDirectory($abs);
        }
        File::ensureDirectoryExists($abs);
        File::copyDirectory($sourceDir, $abs);

        $this->assertNoForbiddenFiles($abs);

        $relative = $dir.'/'.ltrim($entryFile, '/');
        if (! Storage::disk(self::DISK)->exists($relative)) {
            throw new DomainException('INTERACTIVE_FILE_INVALID: Entry file missing after copy.', 422);
        }

        $activity->update([
            'version' => $version,
            'entry_file' => $entryFile,
            'activity_package_path' => $relative,
        ]);

        return $relative;
    }

    public function duplicatePackage(InteractiveActivity $source, InteractiveActivity $target): void
    {
        $from = $this->basePath($source);
        if (! Storage::disk(self::DISK)->exists($from)) {
            return;
        }

        $to = $this->basePath($target, 1);
        Storage::disk(self::DISK)->makeDirectory($to);
        File::copyDirectory(
            Storage::disk(self::DISK)->path($from),
            Storage::disk(self::DISK)->path($to)
        );

        $entry = $source->entry_file ?: 'index.html';
        $target->update([
            'version' => 1,
            'entry_file' => $entry,
            'activity_package_path' => $to.'/'.$entry,
        ]);
    }

    public function signedLaunchUrl(InteractiveActivity $activity, ?\DateTimeInterface $expiresAt = null): ?string
    {
        $path = $this->entryRelativePath($activity);
        if (! $path) {
            return null;
        }

        $expires = $expiresAt ?? now()->addMinutes(60);
        $entry = basename($path);

        return URL::temporarySignedRoute(
            'interactive.activity.package',
            $expires,
            [
                'uuid' => $activity->uuid,
                'version' => $activity->version,
                'path' => $entry,
            ]
        );
    }

    private function nextVersion(InteractiveActivity $activity): int
    {
        if (! $activity->activity_package_path) {
            return max(1, (int) $activity->version);
        }

        return max(1, (int) $activity->version) + 1;
    }

    private function detectEntryFile(InteractiveActivity $activity, int $version): string
    {
        $candidates = ['index.html', 'activity.html', 'game.html'];
        foreach ($candidates as $candidate) {
            if (Storage::disk(self::DISK)->exists($this->basePath($activity, $version).'/'.$candidate)) {
                return $candidate;
            }
        }

        return $activity->entry_file ?: 'index.html';
    }

    private function extractZipSafely(InteractiveActivity $activity, UploadedFile $zip, int $version): void
    {
        $tmp = $zip->getRealPath();
        if (! $tmp) {
            throw new DomainException('INTERACTIVE_FILE_INVALID: Unable to read zip.', 422);
        }

        $archive = new ZipArchive;
        if ($archive->open($tmp) !== true) {
            throw new DomainException('INTERACTIVE_FILE_INVALID: Corrupt zip archive.', 422);
        }

        $base = $this->absolutePath($activity, $version);

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $name = $archive->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            // Strip a single root folder if zip wraps contents
            $normalized = str_replace('\\', '/', $name);
            if (str_contains($normalized, '..') || str_starts_with($normalized, '/')) {
                $archive->close();
                throw new DomainException('INTERACTIVE_FILE_INVALID: Path traversal detected in zip.', 422);
            }

            $ext = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
                $archive->close();
                throw new DomainException("INTERACTIVE_FILE_INVALID: Forbidden file type .{$ext}", 422);
            }

            if ($ext !== '' && ! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
        }

        $archive->extractTo($base);
        $archive->close();

        // Flatten single root directory if present
        $this->flattenSingleRoot($base);
        $this->assertNoForbiddenFiles($base);
    }

    private function flattenSingleRoot(string $base): void
    {
        $entries = array_values(array_filter(scandir($base) ?: [], fn ($e) => $e !== '.' && $e !== '..'));
        if (count($entries) !== 1) {
            return;
        }

        $only = $base.DIRECTORY_SEPARATOR.$entries[0];
        if (! is_dir($only)) {
            return;
        }

        foreach (scandir($only) ?: [] as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            rename($only.DIRECTORY_SEPARATOR.$child, $base.DIRECTORY_SEPARATOR.$child);
        }
        File::deleteDirectory($only);
    }

    private function assertNoForbiddenFiles(string $absoluteDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
                throw new DomainException("INTERACTIVE_FILE_INVALID: Forbidden file type .{$ext}", 422);
            }
            $rel = str_replace('\\', '/', $file->getPathname());
            if (str_contains($rel, '..')) {
                throw new DomainException('INTERACTIVE_FILE_INVALID: Path traversal blocked.', 422);
            }
        }
    }

    private function assertSafeUpload(UploadedFile $file, array $allowedExt): void
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, $allowedExt, true)) {
            throw new DomainException('INTERACTIVE_FILE_INVALID: Extension not allowed.', 422);
        }
        if (in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new DomainException("INTERACTIVE_FILE_INVALID: Forbidden extension .{$ext}", 422);
        }
    }
}
