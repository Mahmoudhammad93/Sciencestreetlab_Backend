<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Observability\Application\Services\ErrorIncidentRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->headers->get('X-Request-Id');
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9._-]{8,128}$/', $id)) {
            $id = (string) Str::ulid();
        }

        $request->headers->set('X-Request-Id', $id);
        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);
        ErrorIncidentRecorder::resetRequestBudget();

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
