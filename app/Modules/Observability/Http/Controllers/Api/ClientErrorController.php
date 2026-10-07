<?php

declare(strict_types=1);

namespace App\Modules\Observability\Http\Controllers\Api;

use App\Modules\Observability\Application\Services\ErrorIncidentRecorder;
use App\Modules\Observability\Application\Services\ErrorRedactor;
use App\Modules\Observability\Domain\FrontendRuntimeError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClientErrorController
{
    public function store(Request $request, ErrorIncidentRecorder $recorder, ErrorRedactor $redactor): JsonResponse
    {
        $maxMessage = (int) config('error_monitoring.frontend.max_message', 500);
        $maxStack = (int) config('error_monitoring.frontend.max_stack', 8000);
        $maxPath = (int) config('error_monitoring.frontend.max_pathname', 500);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.$maxMessage],
            'stack' => ['nullable', 'string', 'max:'.$maxStack],
            'pathname' => ['nullable', 'string', 'max:'.$maxPath],
            'kind' => ['nullable', 'string', 'in:error,unhandledrejection,error-boundary,route'],
            'build' => ['nullable', 'string', 'max:64'],
            'request_id' => ['nullable', 'string', 'max:128'],
        ]);

        $requestId = $request->attributes->get('request_id');
        if (! is_string($requestId) || $requestId === '') {
            $requestId = is_string($data['request_id'] ?? null) ? (string) $data['request_id'] : (string) $request->headers->get('X-Request-Id');
        }

        $stack = isset($data['stack']) && is_string($data['stack'])
            ? $redactor->sanitizeStack($data['stack'], $maxStack)
            : null;

        $exception = new FrontendRuntimeError($data['message']);
        $recorder->record($exception, [
            'source' => 'frontend',
            'module' => 'Frontend',
            'request_id' => is_string($requestId) ? $requestId : null,
            'http_status' => null,
            'context' => [
                'kind' => $data['kind'] ?? 'error',
                'pathname' => $data['pathname'] ?? null,
                'build' => $data['build'] ?? null,
                'client_request_id' => $data['request_id'] ?? null,
                'stack' => $stack,
            ],
        ]);

        return response()->json([
            'ok' => true,
            'request_id' => $request->attributes->get('request_id') ?? $requestId,
        ], 202);
    }
}
