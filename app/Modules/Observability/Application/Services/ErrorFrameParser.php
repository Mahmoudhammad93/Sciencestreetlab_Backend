<?php

declare(strict_types=1);

namespace App\Modules\Observability\Application\Services;

use Illuminate\Database\Eloquent\Model;
use Throwable;

final class ErrorFrameParser
{
    public function __construct(private readonly ErrorPathNormalizer $paths) {}

    /**
     * @return list<array{file:?string,line:?int,class:?string,function:?string,application:bool}>
     */
    public function frames(Throwable $e, int $maxFrames): array
    {
        $frames = [];
        foreach ($e->getTrace() as $frame) {
            if (count($frames) >= $maxFrames) {
                break;
            }
            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : null;
            $file = isset($frame['file']) && is_string($frame['file']) ? $this->paths->normalize($frame['file']) : null;
            $frames[] = [
                'file' => $file,
                'line' => isset($frame['line']) && is_numeric($frame['line']) ? (int) $frame['line'] : null,
                'class' => $class,
                'function' => isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : null,
                'application' => $this->isApplicationClass($class) || $this->isApplicationFile($file),
            ];
        }

        return $frames;
    }

    /**
     * @param  list<array{file:?string,line:?int,class:?string,function:?string,application:bool}>  $frames
     * @return array{class:?string,method:?string}
     */
    public function applicationClass(array $frames): array
    {
        foreach ($frames as $frame) {
            $class = $frame['class'];
            if (! $this->isApplicationClass($class)) {
                continue;
            }
            if ($this->isReporterClass($class)) {
                continue;
            }
            if (str_contains((string) $class, '\\Http\\Middleware\\')) {
                continue;
            }

            return [
                'class' => $class,
                'method' => $frame['function'],
            ];
        }

        return ['class' => null, 'method' => null];
    }

    public function classFromProjectFile(?string $file): ?string
    {
        if (! is_string($file) || $file === '') {
            return null;
        }
        $normalized = str_replace('\\', '/', $file);
        if (! preg_match('#(?:^|/)app/(.+)\.php$#', $normalized, $m)) {
            return null;
        }
        $class = 'App\\'.str_replace('/', '\\', $m[1]);
        if (! class_exists($class)) {
            return null;
        }
        if ($this->isReporterClass($class)) {
            return null;
        }

        return $class;
    }

    private function isReporterClass(string $class): bool
    {
        return str_contains($class, 'ErrorIncidentRecorder')
            || str_contains($class, 'ErrorFrameParser')
            || str_contains($class, 'ErrorContextResolver')
            || str_contains($class, 'ClientErrorController');
    }

    /**
     * @param  list<array{file:?string,line:?int,class:?string,function:?string,application:bool}>  $frames
     */
    public function eloquentModel(array $frames): ?string
    {
        foreach ($frames as $frame) {
            $class = $frame['class'];
            if (! is_string($class) || ! str_starts_with($class, 'App\\')) {
                continue;
            }
            if (! class_exists($class)) {
                continue;
            }
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            return class_basename($class);
        }

        return null;
    }

    /**
     * @param  list<array{file:?string,line:?int,class:?string,function:?string,application:bool}>  $frames
     */
    public function formatTrace(array $frames, string $exceptionFile, int $exceptionLine, int $maxChars): string
    {
        $app = [];
        $other = [];
        foreach ($frames as $i => $frame) {
            $line = sprintf(
                '#%d %s:%s %s%s%s',
                $i,
                $frame['file'] ?? '[internal]',
                $frame['line'] !== null ? (string) $frame['line'] : '?',
                $frame['class'] ?? '',
                ($frame['class'] && $frame['function']) ? '::' : '',
                $frame['function'] ?? ''
            );
            if ($frame['application']) {
                $app[] = $line;
            } else {
                $other[] = $line;
            }
        }

        $header = $exceptionFile.':'.$exceptionLine;
        $body = $header."\n".implode("\n", array_merge($app, $other));
        if (strlen($body) > $maxChars) {
            return substr($body, 0, $maxChars)."\n…[truncated]";
        }

        return $body;
    }

    private function isApplicationClass(?string $class): bool
    {
        return is_string($class) && str_starts_with($class, 'App\\');
    }

    private function isApplicationFile(?string $file): bool
    {
        return is_string($file) && (str_starts_with($file, 'app/') || str_starts_with($file, 'src/'));
    }
}
