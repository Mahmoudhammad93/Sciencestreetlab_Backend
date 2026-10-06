<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Services;

use App\Modules\Commerce\Http\Support\ResolvesCart;
use App\Modules\SocialAttribution\Application\Support\AttributionCookie;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\TrackingLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TrackingRedirectService
{
    public function __construct(
        private readonly DestinationAllowlist $allowlist,
        private readonly AttributionCookie $cookie,
        private readonly AttributionSessionManager $sessions,
        private readonly RecordTrackingClick $clicks,
        private readonly BotDetector $bots,
        private readonly ResolvesCart $resolvesCart,
    ) {}

    public function handle(Request $request, string $code): RedirectResponse|Response
    {
        $code = strtolower(trim($code));
        if ($code === '' || preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code) !== 1) {
            throw new NotFoundHttpException('Tracking link not found.');
        }

        /** @var TrackingLink|null $link */
        $link = TrackingLink::query()
            ->with(['campaign', 'content'])
            ->where('code', $code)
            ->first();

        if ($link === null || ! $link->isRunnable()) {
            throw new NotFoundHttpException('Tracking link not found.');
        }

        try {
            $destinationPath = $this->allowlist->assertSafePath($link->destination_path);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Tracking link destination invalid.');
        }

        $visitorKey = $this->cookie->read($request) ?? $this->cookie->mint();
        $cartSessionId = $this->resolvesCart->resolveGuestSessionId($request);

        // Sanctum Bearer only — never Filament/web session ownership.
        $authenticatedUserId = null;
        if ($request->bearerToken()) {
            $user = Auth::guard('sanctum')->setRequest($request)->user();
            $authenticatedUserId = $user?->id;
        }

        $session = $this->sessions->resolveOrCreate($visitorKey, $cartSessionId, $authenticatedUserId);
        $isBot = $this->bots->isBot($request->userAgent());
        $utms = $this->mergeUtms($link, $request);

        $touchPayload = [
            'campaign_code' => $link->campaign?->code,
            'campaign_name' => $link->campaign?->name,
            'content_title' => $link->content?->title,
            'utm' => $utms,
            'fbclid' => is_string($request->query('fbclid')) ? mb_substr($request->query('fbclid'), 0, 255) : null,
            'gclid' => is_string($request->query('gclid')) ? mb_substr($request->query('gclid'), 0, 255) : null,
            'ttclid' => is_string($request->query('ttclid')) ? mb_substr($request->query('ttclid'), 0, 255) : null,
        ];

        if (! $isBot) {
            $session = $this->sessions->applyHumanTouch($session, $link, $touchPayload);
        }

        $this->clicks->handle($link, $session, $request, $utms, $isBot);

        $target = $this->buildFrontendUrl($destinationPath, $utms, $request);

        return redirect()->away($target, 302)
            ->withCookie($this->cookie->make($visitorKey))
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    /**
     * @return array{utm_source: ?string, utm_medium: ?string, utm_campaign: ?string, utm_content: ?string, utm_term: ?string}
     */
    private function mergeUtms(TrackingLink $link, Request $request): array
    {
        $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
        $out = [];
        foreach ($keys as $key) {
            $inbound = $request->query($key);
            if (is_string($inbound) && trim($inbound) !== '') {
                $out[$key] = mb_substr(trim($inbound), 0, 191);
            } else {
                $default = $link->{$key} ?? null;
                $out[$key] = is_string($default) && $default !== '' ? $default : null;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string|null>  $utms
     */
    private function buildFrontendUrl(string $path, array $utms, Request $request): string
    {
        $frontend = rtrim((string) config('sciencestreet.frontend_url', ''), '/');
        if ($frontend === '') {
            $frontend = rtrim((string) $request->getSchemeAndHttpHost(), '/');
        }

        $query = [];
        foreach ($utms as $k => $v) {
            if (is_string($v) && $v !== '') {
                $query[$k] = $v;
            }
        }
        foreach (['fbclid', 'gclid', 'ttclid'] as $clickId) {
            $val = $request->query($clickId);
            if (is_string($val) && trim($val) !== '') {
                $query[$clickId] = mb_substr(trim($val), 0, 255);
            }
        }

        $url = $frontend.$path;
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $url;
    }
}
