<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Http\Controllers;

use App\Modules\SocialAttribution\Application\Services\TrackingRedirectService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class TrackingRedirectController extends Controller
{
    public function __invoke(Request $request, string $code, TrackingRedirectService $redirects)
    {
        return $redirects->handle($request, $code);
    }
}
