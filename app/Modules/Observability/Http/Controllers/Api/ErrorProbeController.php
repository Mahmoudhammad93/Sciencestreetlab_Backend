<?php

declare(strict_types=1);

namespace App\Modules\Observability\Http\Controllers\Api;

use RuntimeException;

/**
 * Local/testing probes only. Never registered when APP_ENV=production.
 */
final class ErrorProbeController
{
    public function boom(): never
    {
        throw new RuntimeException('observability public api probe');
    }

    public function boomAuth(): never
    {
        throw new RuntimeException('observability customer api probe');
    }

    public function boomAdmin(): never
    {
        throw new RuntimeException('observability filament admin probe');
    }
}
