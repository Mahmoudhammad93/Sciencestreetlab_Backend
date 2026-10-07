<?php

declare(strict_types=1);

namespace App\Modules\Observability\Application\Services;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

final class ErrorContextResolver
{
    /**
     * @return array{type: string, id: ?int}
     */
    public function user(?Request $request): array
    {
        if (ErrorIncidentRecorder::jobContext() !== null || ErrorIncidentRecorder::commandContext() !== null) {
            return ['type' => 'system', 'id' => null];
        }

        if ($request === null) {
            return ['type' => app()->runningInConsole() ? 'system' : 'guest', 'id' => null];
        }

        if ($request->is('api/*')) {
            $bearer = $request->bearerToken();
            if (! is_string($bearer) || $bearer === '') {
                return ['type' => 'guest', 'id' => null];
            }
            $user = Auth::guard('sanctum')->user();
            if ($user instanceof Authenticatable) {
                return ['type' => 'customer', 'id' => $this->idOf($user)];
            }

            return ['type' => 'guest', 'id' => null];
        }

        if ($this->isAdminRequest($request)) {
            $user = Auth::guard('web')->user() ?? Auth::user();
            if ($user instanceof Authenticatable) {
                return ['type' => 'admin', 'id' => $this->idOf($user)];
            }

            return ['type' => 'guest', 'id' => null];
        }

        if (app()->runningInConsole()) {
            return ['type' => 'system', 'id' => null];
        }

        $user = Auth::user();
        if ($user instanceof Authenticatable) {
            return ['type' => 'customer', 'id' => $this->idOf($user)];
        }

        return ['type' => 'guest', 'id' => null];
    }

    public function source(?Request $request): string
    {
        $hint = ErrorIncidentRecorder::sourceHint();
        if (is_string($hint) && $hint !== '') {
            return $hint;
        }
        if (ErrorIncidentRecorder::jobContext() !== null) {
            return 'queue';
        }
        if (ErrorIncidentRecorder::commandContext() !== null) {
            return 'command';
        }
        if ($request === null) {
            return app()->runningInConsole() ? 'command' : 'http';
        }
        if ($this->isAdminRequest($request)) {
            return 'filament';
        }
        if ($request->is('api/*')) {
            return 'http';
        }

        return 'http';
    }

    public function httpStatus(Throwable $e, ?Request $request): ?int
    {
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return $e->getStatusCode();
        }
        if ($request !== null && $request->attributes->has('error_http_status')) {
            $status = $request->attributes->get('error_http_status');

            return is_numeric($status) ? (int) $status : null;
        }

        return $request !== null ? 500 : null;
    }

    public function isAdminRequest(?Request $request): bool
    {
        if ($request === null) {
            return false;
        }
        try {
            if (class_exists(Filament::class) && Filament::isServing()) {
                return true;
            }
        } catch (Throwable) {
            // Filament may not be booted on API/console.
        }

        if ($request->is('admin') || $request->is('admin/*')) {
            return true;
        }

        if ($request->is('livewire/*')) {
            $referer = (string) $request->headers->get('Referer', '');

            return str_contains($referer, '/admin');
        }

        return false;
    }

    private function idOf(Authenticatable $user): ?int
    {
        $id = $user->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }
}
