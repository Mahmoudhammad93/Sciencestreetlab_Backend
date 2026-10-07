<?php

declare(strict_types=1);

namespace App\Modules\Observability\Application\Services;

use App\Modules\Observability\Domain\FrontendRuntimeError;
use App\Modules\Observability\Infrastructure\Persistence\Models\ErrorIncident;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ErrorIncidentRecorder
{
    private static bool $recording = false;

    private static int $persistAttempts = 0;

    private const MAX_PERSISTS_PER_REQUEST = 3;

    /** @var array{job:?string,queue:?string,attempts:?int}|null */
    private static ?array $jobContext = null;

    private static ?string $commandContext = null;

    private static ?string $sourceHint = null;

    public function __construct(
        private readonly ErrorFingerprint $fingerprint,
        private readonly ErrorRedactor $redactor,
        private readonly ErrorPathNormalizer $paths,
        private readonly ErrorFrameParser $frames,
        private readonly ErrorModuleDetector $modules,
        private readonly ErrorContextResolver $contextResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     */
    public function record(Throwable $e, array $extra = []): void
    {
        if (self::$recording) {
            return;
        }
        if (! (bool) config('error_monitoring.enabled', true)) {
            return;
        }
        if ($this->shouldIgnore($e, $extra)) {
            return;
        }
        if (self::$persistAttempts >= self::MAX_PERSISTS_PER_REQUEST) {
            $this->fallbackLog($e, new \RuntimeException('error_incident_max_per_request'));

            return;
        }

        self::$recording = true;
        self::$persistAttempts++;
        try {
            $this->persist($e, $extra);
        } catch (Throwable $inner) {
            $this->fallbackLog($e, $inner);
        } finally {
            self::$recording = false;
        }
    }

    /**
     * @return array{job:?string,queue:?string,attempts:?int}|null
     */
    public static function jobContext(): ?array
    {
        return self::$jobContext;
    }

    public static function commandContext(): ?string
    {
        return self::$commandContext;
    }

    public static function sourceHint(): ?string
    {
        return self::$sourceHint;
    }

    /**
     * @param  array{job:?string,queue:?string,attempts:?int}  $context
     */
    public static function setJobContext(array $context): void
    {
        self::$jobContext = $context;
    }

    public static function clearJobContext(): void
    {
        self::$jobContext = null;
    }

    public static function setCommandContext(?string $command): void
    {
        self::$commandContext = $command;
    }

    public static function setSourceHint(?string $source): void
    {
        self::$sourceHint = $source;
    }

    public static function resetRuntimeState(): void
    {
        self::$recording = false;
        self::$persistAttempts = 0;
        self::$jobContext = null;
        self::$commandContext = null;
        self::$sourceHint = null;
    }

    public static function resetRequestBudget(): void
    {
        self::$persistAttempts = 0;
        self::$recording = false;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function shouldIgnore(Throwable $e, array $extra): bool
    {
        if (($extra['force'] ?? false) === true) {
            return false;
        }
        if ($e instanceof ValidationException || $e instanceof AuthenticationException || $e instanceof AuthorizationException) {
            return true;
        }
        if ($e instanceof TokenMismatchException) {
            return true;
        }
        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return true;
        }
        if ($e instanceof HttpExceptionInterface) {
            $ignored = config('error_monitoring.ignore_http_statuses', [401, 403, 404, 419, 422, 429]);

            return in_array($e->getStatusCode(), $ignored, true);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function persist(Throwable $e, array $extra): void
    {
        $request = $this->currentRequest();
        $maxFrames = (int) config('error_monitoring.trace.max_frames', 40);
        $maxChars = (int) config('error_monitoring.trace.max_chars', 16000);
        $maxMessage = (int) config('error_monitoring.message.max_chars', 2000);

        $frames = $this->frames->frames($e, $maxFrames);
        $file = $this->paths->normalize($e->getFile());
        $app = $this->frames->applicationClass($frames);
        if ($app['class'] === null) {
            $fromFile = $this->frames->classFromProjectFile($file);
            if (is_string($fromFile)) {
                $app['class'] = $fromFile;
                foreach ($frames as $frame) {
                    if (($frame['class'] ?? null) === $fromFile && is_string($frame['function'] ?? null)) {
                        $app['method'] = $frame['function'];
                        break;
                    }
                }
            }
        }
        $line = $e->getLine();
        $route = $request?->route()?->getName();
        $path = $request?->path();
        $job = self::$jobContext;
        $command = self::$commandContext;
        $source = is_string($extra['source'] ?? null) ? (string) $extra['source'] : $this->contextResolver->source($request);
        $controller = $request?->route()?->getControllerClass();
        $module = is_string($extra['module'] ?? null)
            ? (string) $extra['module']
            : $this->modules->detect($frames, $file, $route, $path, $job['job'] ?? null, $command, is_string($controller) ? $controller : null, $source);

        $model = is_string($extra['eloquent_model'] ?? null)
            ? class_basename((string) $extra['eloquent_model'])
            : $this->frames->eloquentModel($frames);

        $user = $this->contextResolver->user($request);
        $requestId = $this->resolveRequestId($request, $extra);
        $status = isset($extra['http_status']) && is_numeric($extra['http_status'])
            ? (int) $extra['http_status']
            : $this->contextResolver->httpStatus($e, $request);
        if ($source === 'frontend') {
            $status = isset($extra['http_status']) && is_numeric($extra['http_status']) ? (int) $extra['http_status'] : null;
        }

        $message = Str::limit($e->getMessage() !== '' ? $e->getMessage() : $e::class, $maxMessage, '');
        $level = is_string($extra['level'] ?? null) ? (string) $extra['level'] : 'error';

        $fingerprint = $this->fingerprint->make([
            'exception_class' => $e::class,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'route_name' => $route,
            'module' => $module,
            'source' => $source,
        ]);

        $context = $this->buildContext($e, $request, $extra, $job, $command);
        $trace = $this->redactor->sanitizeStack(
            $this->frames->formatTrace($frames, (string) $file, $line, $maxChars),
            $maxChars
        );

        $now = now();
        $payload = [
            'level' => $level,
            'exception_class' => $e::class,
            'message' => $message,
            'http_status' => $status,
            'module' => $module,
            'source' => $source,
            'application_class' => $app['class'],
            'application_method' => $app['method'],
            'eloquent_model' => $model,
            'file' => $file,
            'line' => $line,
            'route_name' => $route,
            'request_method' => $request?->method(),
            'request_path' => $path !== null ? '/'.$path : null,
            'user_id' => $user['id'],
            'user_type' => $user['type'],
            'request_id' => $requestId,
            'context' => $context,
            'trace' => $trace,
            'last_seen_at' => $now,
        ];

        DB::transaction(function () use ($fingerprint, $payload, $now): void {
            $existing = ErrorIncident::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
            if ($existing instanceof ErrorIncident) {
                $existing->fill($payload);
                $existing->occurrences = (int) $existing->occurrences + 1;
                if ($existing->isResolved()) {
                    $existing->resolved_at = null;
                    $existing->resolved_by = null;
                }
                $existing->save();

                return;
            }

            try {
                ErrorIncident::query()->create(array_merge($payload, [
                    'fingerprint' => $fingerprint,
                    'occurrences' => 1,
                    'first_seen_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                $raced = ErrorIncident::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
                if ($raced instanceof ErrorIncident) {
                    $raced->fill($payload);
                    $raced->occurrences = (int) $raced->occurrences + 1;
                    if ($raced->isResolved()) {
                        $raced->resolved_at = null;
                        $raced->resolved_by = null;
                    }
                    $raced->save();
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array{job:?string,queue:?string,attempts:?int}|null  $job
     * @return array<string, mixed>
     */
    private function buildContext(Throwable $e, ?Request $request, array $extra, ?array $job, ?string $command): array
    {
        $context = [
            'exception' => $e::class,
        ];

        if ($job !== null) {
            $context['job'] = $job['job'] ?? null;
            $context['queue'] = $job['queue'] ?? null;
            $context['attempts'] = $job['attempts'] ?? null;
        }
        if (is_string($command) && $command !== '') {
            $context['command'] = $command;
        }

        if ($e instanceof QueryException) {
            $context['query'] = [
                'connection' => $e->getConnectionName(),
                'sqlstate' => $e->errorInfo[0] ?? null,
                'driver_code' => $e->errorInfo[1] ?? null,
            ];
        }

        if ($request !== null) {
            $context['request'] = [
                'query_keys' => array_values(array_keys($request->query->all())),
                'input_keys' => array_values(array_keys($request->except(['password', 'password_confirmation']))),
                'content_type' => $request->headers->get('Content-Type'),
            ];
        }

        if (isset($extra['context']) && is_array($extra['context'])) {
            $context['extra'] = $this->redactor->redact($extra['context']);
        }

        foreach (['provider', 'operation', 'external_status', 'external_id'] as $safe) {
            if (isset($extra[$safe]) && (is_string($extra[$safe]) || is_int($extra[$safe]))) {
                $context[$safe] = $extra[$safe];
            }
        }

        return $this->redactor->redact($context);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function resolveRequestId(?Request $request, array $extra): string
    {
        if (isset($extra['request_id']) && is_string($extra['request_id']) && $extra['request_id'] !== '') {
            return $extra['request_id'];
        }
        $fromRequest = $request?->attributes->get('request_id');
        if (is_string($fromRequest) && $fromRequest !== '') {
            return $fromRequest;
        }
        $header = $request?->headers->get('X-Request-Id');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        return (string) Str::ulid();
    }

    private function currentRequest(): ?Request
    {
        try {
            $request = request();
        } catch (Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }

    private function fallbackLog(Throwable $original, Throwable $persistError): void
    {
        try {
            Log::error('error_incident_persist_failed', [
                'exception' => $original::class,
                'message' => $original->getMessage(),
                'persist_error' => $persistError->getMessage(),
                'request_id' => request()?->attributes->get('request_id'),
            ]);
        } catch (Throwable) {
            // Last resort: never throw from the reporter.
        }
    }
}
