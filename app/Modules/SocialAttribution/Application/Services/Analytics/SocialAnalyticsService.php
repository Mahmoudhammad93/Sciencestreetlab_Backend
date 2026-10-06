<?php

declare(strict_types=1);

namespace App\Modules\SocialAttribution\Application\Services\Analytics;

use App\Modules\SocialAttribution\Domain\Enums\AttributionPlatform;
use App\Modules\SocialAttribution\Domain\Enums\ConversionEventName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SocialAnalyticsService
{
    /** @var list<string> */
    private const DASHBOARD_PLATFORMS = [
        AttributionPlatform::Instagram->value,
        AttributionPlatform::Facebook->value,
        AttributionPlatform::YouTube->value,
        AttributionPlatform::TikTok->value,
    ];

    public function __construct(
        private readonly ExternalPlatformMetricsProvider $externalMetrics,
    ) {}

    /**
     * @return array{
     *   kpis: array<string, mixed>,
     *   platforms: list<array<string, mixed>>,
     *   campaigns: list<array<string, mixed>>,
     *   contents: list<array<string, mixed>>,
     *   tracking_links: list<array<string, mixed>>,
     *   trend: list<array<string, mixed>>,
     *   unattributed: array{purchases: int, revenues: list<array{currency: string, amount: float}>},
     *   empty: bool
     * }
     */
    public function dashboard(AnalyticsFilters $filters): array
    {
        $clickStats = $this->clickStatsByPlatform($filters);
        $purchaseStats = $this->purchaseStatsByPlatform($filters);
        $campaigns = $this->campaignPerformance($filters);
        $contents = $this->contentPerformance($filters);
        $links = $this->trackingLinkPerformance($filters);
        $trend = $this->dailyTrend($filters);
        $unattributed = $this->unattributedPurchases($filters);

        $platforms = [];
        foreach (self::DASHBOARD_PLATFORMS as $platform) {
            if ($filters->platform !== null && $filters->platform !== $platform) {
                continue;
            }
            $clicks = $clickStats[$platform] ?? ['raw' => 0, 'human' => 0, 'unique' => 0];
            $purchases = $purchaseStats[$platform] ?? ['purchases' => 0, 'revenues' => []];
            $external = $this->externalMetrics->metricsFor($platform);
            $platforms[] = $this->composeRow($platform, $clicks, $purchases, $external);
        }

        $kpis = $this->composeKpis($platforms, $clickStats, $purchaseStats, $filters);

        $hasClicks = collect($clickStats)->sum(fn ($r) => (int) ($r['raw'] ?? 0)) > 0;
        $hasPurchases = collect($purchaseStats)->sum(fn ($r) => (int) ($r['purchases'] ?? 0)) > 0
            || ($unattributed['purchases'] ?? 0) > 0;

        return [
            'kpis' => $kpis,
            'platforms' => $platforms,
            'campaigns' => $campaigns,
            'contents' => $contents,
            'tracking_links' => $links,
            'trend' => $trend,
            'unattributed' => $unattributed,
            'empty' => ! $hasClicks && ! $hasPurchases,
        ];
    }

    /**
     * @return array<string, array{raw: int, human: int, unique: int}>
     */
    public function clickStatsByPlatform(AnalyticsFilters $filters): array
    {
        $q = DB::table('tracking_link_clicks as c')
            ->join('tracking_links as l', 'l.id', '=', 'c.tracking_link_id')
            ->whereBetween('c.occurred_at', [$filters->from, $filters->to]);

        if ($filters->platform !== null) {
            $q->where('l.platform', $filters->platform);
        }

        $rows = $q->selectRaw('l.platform as platform')
            ->selectRaw('COUNT(*) as raw_clicks')
            ->selectRaw('SUM(CASE WHEN c.is_bot = 0 THEN 1 ELSE 0 END) as human_clicks')
            ->selectRaw('SUM(CASE WHEN c.is_unique_human = 1 THEN 1 ELSE 0 END) as unique_clicks')
            ->groupBy('l.platform')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->platform] = [
                'raw' => (int) $row->raw_clicks,
                'human' => (int) $row->human_clicks,
                'unique' => (int) $row->unique_clicks,
            ];
        }

        return $out;
    }

    /**
     * Purchase/revenue by platform. Never joins clicks (duplicate-safe).
     *
     * @return array<string, array{purchases: int, revenues: list<array{currency: string, amount: float}>}>
     */
    public function purchaseStatsByPlatform(AnalyticsFilters $filters): array
    {
        if ($filters->attributionModel === 'first_touch') {
            return $this->purchaseStatsFirstTouch($filters);
        }

        $q = DB::table('attribution_conversions as ac')
            ->where('ac.event_name', ConversionEventName::Purchase->value)
            ->where('ac.is_attributed', true)
            ->whereNotNull('ac.platform')
            ->whereBetween('ac.occurred_at', [$filters->from, $filters->to]);

        if ($filters->platform !== null) {
            $q->where('ac.platform', $filters->platform);
        }

        $rows = $q->selectRaw('ac.platform as platform')
            ->selectRaw('ac.currency as currency')
            ->selectRaw('COUNT(*) as purchases')
            ->selectRaw('COALESCE(SUM(ac.value), 0) as revenue')
            ->groupBy('ac.platform', 'ac.currency')
            ->get();

        return $this->foldPurchaseRows($rows);
    }

    /**
     * @return array<string, array{purchases: int, revenues: list<array{currency: string, amount: float}>}>
     */
    private function purchaseStatsFirstTouch(AnalyticsFilters $filters): array
    {
        $platformExpr = $this->jsonText('oa.first_touch', 'platform');

        $q = DB::table('attribution_conversions as ac')
            ->join('order_attributions as oa', 'oa.order_id', '=', 'ac.order_id')
            ->where('ac.event_name', ConversionEventName::Purchase->value)
            ->where('oa.is_attributed', true)
            ->whereBetween('ac.occurred_at', [$filters->from, $filters->to])
            ->whereRaw("{$platformExpr} IS NOT NULL")
            ->whereRaw("{$platformExpr} != ''");

        if ($filters->platform !== null) {
            $q->whereRaw("{$platformExpr} = ?", [$filters->platform]);
        }

        $rows = $q->selectRaw("{$platformExpr} as platform")
            ->selectRaw('ac.currency as currency')
            ->selectRaw('COUNT(*) as purchases')
            ->selectRaw('COALESCE(SUM(ac.value), 0) as revenue')
            ->groupBy(DB::raw($platformExpr), 'ac.currency')
            ->get();

        return $this->foldPurchaseRows($rows);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, array{purchases: int, revenues: list<array{currency: string, amount: float}>}>
     */
    private function foldPurchaseRows(Collection $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $platform = (string) $row->platform;
            if ($platform === '') {
                continue;
            }
            $out[$platform] ??= ['purchases' => 0, 'revenues' => []];
            $out[$platform]['purchases'] += (int) $row->purchases;
            $currency = (string) ($row->currency ?: 'EGP');
            $amount = (float) $row->revenue;
            $found = false;
            foreach ($out[$platform]['revenues'] as &$rev) {
                if ($rev['currency'] === $currency) {
                    $rev['amount'] += $amount;
                    $found = true;
                    break;
                }
            }
            unset($rev);
            if (! $found) {
                $out[$platform]['revenues'][] = ['currency' => $currency, 'amount' => $amount];
            }
        }

        return $out;
    }

    /**
     * @return array{purchases: int, revenues: list<array{currency: string, amount: float}>}
     */
    public function unattributedPurchases(AnalyticsFilters $filters): array
    {
        $rows = DB::table('attribution_conversions as ac')
            ->where('ac.event_name', ConversionEventName::Purchase->value)
            ->where(function ($q): void {
                $q->where('ac.is_attributed', false)->orWhereNull('ac.platform');
            })
            ->whereBetween('ac.occurred_at', [$filters->from, $filters->to])
            ->selectRaw('ac.currency as currency')
            ->selectRaw('COUNT(*) as purchases')
            ->selectRaw('COALESCE(SUM(ac.value), 0) as revenue')
            ->groupBy('ac.currency')
            ->get();

        $purchases = 0;
        $revenues = [];
        foreach ($rows as $row) {
            $purchases += (int) $row->purchases;
            $revenues[] = [
                'currency' => (string) ($row->currency ?: 'EGP'),
                'amount' => (float) $row->revenue,
            ];
        }

        return ['purchases' => $purchases, 'revenues' => $revenues];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function campaignPerformance(AnalyticsFilters $filters): array
    {
        $clickRows = DB::table('tracking_link_clicks as c')
            ->join('tracking_links as l', 'l.id', '=', 'c.tracking_link_id')
            ->leftJoin('social_campaigns as camp', 'camp.id', '=', 'l.campaign_id')
            ->whereBetween('c.occurred_at', [$filters->from, $filters->to])
            ->when($filters->platform, fn ($q, $p) => $q->where('l.platform', $p))
            ->whereNotNull('l.campaign_id')
            ->selectRaw('l.campaign_id as campaign_id')
            ->selectRaw('MAX(camp.name) as name')
            ->selectRaw('MAX(camp.code) as code')
            ->selectRaw('MAX(camp.platform) as platform')
            ->selectRaw('MAX(camp.channel_kind) as channel_kind')
            ->selectRaw('COUNT(*) as raw_clicks')
            ->selectRaw('SUM(CASE WHEN c.is_unique_human = 1 THEN 1 ELSE 0 END) as unique_clicks')
            ->groupBy('l.campaign_id')
            ->get()
            ->keyBy('campaign_id');

        $purchaseRows = $this->purchaseStatsByDimension($filters, 'campaign');

        $ids = $clickRows->keys()->merge(collect($purchaseRows)->keys())->unique();
        $out = [];
        foreach ($ids as $id) {
            $c = $clickRows->get($id);
            $p = $purchaseRows[$id] ?? ['purchases' => 0, 'revenues' => [], 'platform' => null, 'name' => null, 'code' => null, 'channel_kind' => null];
            $unique = (int) ($c->unique_clicks ?? 0);
            $purchases = (int) ($p['purchases'] ?? 0);
            $revenue = $this->primaryRevenue($p['revenues'] ?? []);
            $out[] = [
                'campaign_id' => (int) $id,
                'name' => $c->name ?? $p['name'] ?? ('#'.$id),
                'code' => $c->code ?? $p['code'] ?? null,
                'platform' => $c->platform ?? $p['platform'] ?? null,
                'channel_kind' => $c->channel_kind ?? $p['channel_kind'] ?? null,
                'raw_clicks' => (int) ($c->raw_clicks ?? 0),
                'unique_clicks' => $unique,
                'purchases' => $purchases,
                'revenues' => $p['revenues'] ?? [],
                'revenue' => $revenue,
                'conversion_rate' => $this->rate($purchases, $unique),
                'aov' => $this->aov($revenue['amount'] ?? 0.0, $purchases),
            ];
        }

        usort($out, fn ($a, $b) => ($b['revenue']['amount'] ?? 0) <=> ($a['revenue']['amount'] ?? 0)
            ?: ($b['unique_clicks'] <=> $a['unique_clicks']));

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function contentPerformance(AnalyticsFilters $filters): array
    {
        $clickRows = DB::table('tracking_link_clicks as c')
            ->join('tracking_links as l', 'l.id', '=', 'c.tracking_link_id')
            ->leftJoin('social_contents as sc', 'sc.id', '=', 'l.social_content_id')
            ->leftJoin('social_campaigns as camp', 'camp.id', '=', 'sc.campaign_id')
            ->whereBetween('c.occurred_at', [$filters->from, $filters->to])
            ->when($filters->platform, fn ($q, $p) => $q->where('l.platform', $p))
            ->whereNotNull('l.social_content_id')
            ->selectRaw('l.social_content_id as content_id')
            ->selectRaw('MAX(sc.title) as title')
            ->selectRaw('MAX(sc.content_type) as content_type')
            ->selectRaw('MAX(sc.platform) as platform')
            ->selectRaw('MAX(camp.name) as campaign_name')
            ->selectRaw('COUNT(*) as raw_clicks')
            ->selectRaw('SUM(CASE WHEN c.is_unique_human = 1 THEN 1 ELSE 0 END) as unique_clicks')
            ->groupBy('l.social_content_id')
            ->get()
            ->keyBy('content_id');

        $purchaseRows = $this->purchaseStatsByDimension($filters, 'content');

        $ids = $clickRows->keys()->merge(collect($purchaseRows)->keys())->unique();
        $out = [];
        foreach ($ids as $id) {
            $c = $clickRows->get($id);
            $p = $purchaseRows[$id] ?? ['purchases' => 0, 'revenues' => []];
            $unique = (int) ($c->unique_clicks ?? 0);
            $purchases = (int) ($p['purchases'] ?? 0);
            $revenue = $this->primaryRevenue($p['revenues'] ?? []);
            $external = $this->externalMetrics->metricsFor((string) ($c->platform ?? $p['platform'] ?? 'other'));
            $out[] = [
                'content_id' => (int) $id,
                'title' => $c->title ?? $p['title'] ?? ('#'.$id),
                'content_type' => $c->content_type ?? $p['content_type'] ?? null,
                'platform' => $c->platform ?? $p['platform'] ?? null,
                'campaign_name' => $c->campaign_name ?? $p['campaign_name'] ?? null,
                'views_available' => $external['available'],
                'views' => $external['views'],
                'raw_clicks' => (int) ($c->raw_clicks ?? 0),
                'unique_clicks' => $unique,
                'purchases' => $purchases,
                'revenues' => $p['revenues'] ?? [],
                'revenue' => $revenue,
                'conversion_rate' => $this->rate($purchases, $unique),
            ];
        }

        usort($out, fn ($a, $b) => ($b['revenue']['amount'] ?? 0) <=> ($a['revenue']['amount'] ?? 0)
            ?: ($b['unique_clicks'] <=> $a['unique_clicks']));

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function trackingLinkPerformance(AnalyticsFilters $filters): array
    {
        $clickRows = DB::table('tracking_link_clicks as c')
            ->join('tracking_links as l', 'l.id', '=', 'c.tracking_link_id')
            ->leftJoin('social_campaigns as camp', 'camp.id', '=', 'l.campaign_id')
            ->leftJoin('social_contents as sc', 'sc.id', '=', 'l.social_content_id')
            ->whereBetween('c.occurred_at', [$filters->from, $filters->to])
            ->when($filters->platform, fn ($q, $p) => $q->where('l.platform', $p))
            ->selectRaw('l.id as tracking_link_id')
            ->selectRaw('MAX(l.code) as code')
            ->selectRaw('MAX(l.platform) as platform')
            ->selectRaw('MAX(l.destination_path) as destination_path')
            ->selectRaw('MAX(camp.name) as campaign_name')
            ->selectRaw('MAX(sc.title) as content_title')
            ->selectRaw('COUNT(*) as raw_clicks')
            ->selectRaw('SUM(CASE WHEN c.is_unique_human = 1 THEN 1 ELSE 0 END) as unique_clicks')
            ->groupBy('l.id')
            ->get()
            ->keyBy('tracking_link_id');

        $purchaseRows = $this->purchaseStatsByDimension($filters, 'tracking_link');

        $ids = $clickRows->keys()->merge(collect($purchaseRows)->keys())->unique();
        $out = [];
        foreach ($ids as $id) {
            $c = $clickRows->get($id);
            $p = $purchaseRows[$id] ?? ['purchases' => 0, 'revenues' => []];
            $unique = (int) ($c->unique_clicks ?? 0);
            $purchases = (int) ($p['purchases'] ?? 0);
            $code = $c->code ?? $p['code'] ?? null;
            $revenue = $this->primaryRevenue($p['revenues'] ?? []);
            $out[] = [
                'tracking_link_id' => (int) $id,
                'code' => $code,
                'tracking_url' => $code ? url('/go/'.$code) : null,
                'platform' => $c->platform ?? $p['platform'] ?? null,
                'campaign_name' => $c->campaign_name ?? $p['campaign_name'] ?? null,
                'content_title' => $c->content_title ?? $p['content_title'] ?? null,
                'destination_path' => $c->destination_path ?? $p['destination_path'] ?? null,
                'raw_clicks' => (int) ($c->raw_clicks ?? 0),
                'unique_clicks' => $unique,
                'purchases' => $purchases,
                'revenues' => $p['revenues'] ?? [],
                'revenue' => $revenue,
                'conversion_rate' => $this->rate($purchases, $unique),
            ];
        }

        usort($out, fn ($a, $b) => ($b['unique_clicks'] <=> $a['unique_clicks']));

        return $out;
    }

    /**
     * @return list<array{date: string, unique_clicks: int, purchases: int, revenue: float, currency: string}>
     */
    public function dailyTrend(AnalyticsFilters $filters): array
    {
        $clicks = DB::table('tracking_link_clicks as c')
            ->join('tracking_links as l', 'l.id', '=', 'c.tracking_link_id')
            ->whereBetween('c.occurred_at', [$filters->from, $filters->to])
            ->when($filters->platform, fn ($q, $p) => $q->where('l.platform', $p))
            ->where('c.is_unique_human', true)
            ->selectRaw($this->dateExpr('c.occurred_at').' as day')
            ->selectRaw('COUNT(*) as unique_clicks')
            ->groupBy(DB::raw($this->dateExpr('c.occurred_at')))
            ->pluck('unique_clicks', 'day');

        $purchasesQ = DB::table('attribution_conversions as ac')
            ->where('ac.event_name', ConversionEventName::Purchase->value)
            ->whereBetween('ac.occurred_at', [$filters->from, $filters->to]);

        if ($filters->attributionModel === 'first_touch') {
            $platformExpr = $this->jsonText('oa.first_touch', 'platform');
            $purchasesQ->join('order_attributions as oa', 'oa.order_id', '=', 'ac.order_id')
                ->where('oa.is_attributed', true)
                ->when($filters->platform, fn ($q, $p) => $q->whereRaw("{$platformExpr} = ?", [$p]));
        } else {
            $purchasesQ->where('ac.is_attributed', true)
                ->when($filters->platform, fn ($q, $p) => $q->where('ac.platform', $p));
        }

        $purchaseRows = $purchasesQ
            ->selectRaw($this->dateExpr('ac.occurred_at').' as day')
            ->selectRaw('COUNT(*) as purchases')
            ->selectRaw('COALESCE(SUM(ac.value), 0) as revenue')
            ->selectRaw('MAX(ac.currency) as currency')
            ->groupBy(DB::raw($this->dateExpr('ac.occurred_at')))
            ->get()
            ->keyBy('day');

        $days = collect($clicks->keys())->merge($purchaseRows->keys())->unique()->sort()->values();
        $out = [];
        foreach ($days as $day) {
            $p = $purchaseRows->get($day);
            $out[] = [
                'date' => (string) $day,
                'unique_clicks' => (int) ($clicks[$day] ?? 0),
                'purchases' => (int) ($p->purchases ?? 0),
                'revenue' => (float) ($p->revenue ?? 0),
                'currency' => (string) ($p->currency ?? 'EGP'),
            ];
        }

        return $out;
    }

    /**
     * @param  'campaign'|'content'|'tracking_link'  $dimension
     * @return array<int|string, array<string, mixed>>
     */
    private function purchaseStatsByDimension(AnalyticsFilters $filters, string $dimension): array
    {
        if ($filters->attributionModel === 'first_touch') {
            $field = match ($dimension) {
                'campaign' => $this->jsonInt('oa.first_touch', 'campaign_id'),
                'content' => $this->jsonInt('oa.first_touch', 'content_id'),
                default => $this->jsonInt('oa.first_touch', 'tracking_link_id'),
            };
            $platformExpr = $this->jsonText('oa.first_touch', 'platform');

            $q = DB::table('attribution_conversions as ac')
                ->join('order_attributions as oa', 'oa.order_id', '=', 'ac.order_id')
                ->where('ac.event_name', ConversionEventName::Purchase->value)
                ->where('oa.is_attributed', true)
                ->whereBetween('ac.occurred_at', [$filters->from, $filters->to])
                ->whereRaw("{$field} IS NOT NULL");

            if ($filters->platform !== null) {
                $q->whereRaw("{$platformExpr} = ?", [$filters->platform]);
            }

            $rows = $q->selectRaw("{$field} as dim_id")
                ->selectRaw('ac.currency as currency')
                ->selectRaw('COUNT(*) as purchases')
                ->selectRaw('COALESCE(SUM(ac.value), 0) as revenue')
                ->selectRaw("MAX({$platformExpr}) as platform")
                ->groupBy(DB::raw($field), 'ac.currency')
                ->get();
        } else {
            $column = match ($dimension) {
                'campaign' => 'ac.campaign_id',
                'content' => 'ac.content_id',
                default => 'ac.tracking_link_id',
            };

            $q = DB::table('attribution_conversions as ac')
                ->where('ac.event_name', ConversionEventName::Purchase->value)
                ->where('ac.is_attributed', true)
                ->whereBetween('ac.occurred_at', [$filters->from, $filters->to])
                ->whereNotNull($column);

            if ($filters->platform !== null) {
                $q->where('ac.platform', $filters->platform);
            }

            $rows = $q->selectRaw("{$column} as dim_id")
                ->selectRaw('ac.currency as currency')
                ->selectRaw('COUNT(*) as purchases')
                ->selectRaw('COALESCE(SUM(ac.value), 0) as revenue')
                ->selectRaw('MAX(ac.platform) as platform')
                ->groupBy(DB::raw($column), 'ac.currency')
                ->get();
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->dim_id;
            $out[$id] ??= [
                'purchases' => 0,
                'revenues' => [],
                'platform' => $row->platform,
            ];
            $out[$id]['purchases'] += (int) $row->purchases;
            $currency = (string) ($row->currency ?: 'EGP');
            $amount = (float) $row->revenue;
            $found = false;
            foreach ($out[$id]['revenues'] as &$rev) {
                if ($rev['currency'] === $currency) {
                    $rev['amount'] += $amount;
                    $found = true;
                    break;
                }
            }
            unset($rev);
            if (! $found) {
                $out[$id]['revenues'][] = ['currency' => $currency, 'amount' => $amount];
            }
        }

        return $out;
    }

    /**
     * @param  array{raw: int, human: int, unique: int}  $clicks
     * @param  array{purchases: int, revenues: list<array{currency: string, amount: float}>}  $purchases
     * @param  array{available: bool, views: ?int, reach: ?int, impressions: ?int, label: string}  $external
     * @return array<string, mixed>
     */
    private function composeRow(string $platform, array $clicks, array $purchases, array $external): array
    {
        $unique = $clicks['unique'];
        $purchaseCount = $purchases['purchases'];
        $revenue = $this->primaryRevenue($purchases['revenues']);

        return [
            'platform' => $platform,
            // External platform content/video views (Meta/YouTube/TikTok APIs) — never fabricated.
            'views_available' => $external['available'],
            'views' => $external['views'],
            'views_label' => $external['available'] ? (string) $external['views'] : 'unavailable',
            // Internal first-party landing page views — not implemented yet (diagnostic Phase).
            'landing_page_views_available' => false,
            'landing_page_views' => null,
            'raw_clicks' => $clicks['raw'],
            'human_clicks' => $clicks['human'],
            'unique_clicks' => $unique,
            'purchases' => $purchaseCount,
            'revenues' => $purchases['revenues'],
            'revenue' => $revenue,
            'conversion_rate' => $this->rate($purchaseCount, $unique),
            'aov' => $this->aov($revenue['amount'], $purchaseCount),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $platforms
     * @param  array<string, array{raw: int, human: int, unique: int}>  $clickStats
     * @param  array<string, array{purchases: int, revenues: list<array{currency: string, amount: float}>}>  $purchaseStats
     * @return array<string, mixed>
     */
    private function composeKpis(array $platforms, array $clickStats, array $purchaseStats, AnalyticsFilters $filters): array
    {
        $raw = collect($clickStats)->sum(fn ($r) => (int) $r['raw']);
        $unique = collect($clickStats)->sum(fn ($r) => (int) $r['unique']);
        $purchases = collect($purchaseStats)->sum(fn ($r) => (int) $r['purchases']);

        $revenueByCurrency = [];
        foreach ($purchaseStats as $stat) {
            foreach ($stat['revenues'] as $rev) {
                $currency = $rev['currency'];
                $revenueByCurrency[$currency] = ($revenueByCurrency[$currency] ?? 0) + $rev['amount'];
            }
        }

        $multiCurrency = count($revenueByCurrency) > 1;
        $primaryCurrency = array_key_first($revenueByCurrency) ?: 'EGP';
        $primaryAmount = $multiCurrency ? null : (float) ($revenueByCurrency[$primaryCurrency] ?? 0);

        $bestPlatform = collect($platforms)
            ->sortByDesc(fn ($p) => $p['revenue']['amount'] ?? 0)
            ->first();

        return [
            'raw_clicks' => (int) $raw,
            'unique_clicks' => (int) $unique,
            'purchases' => (int) $purchases,
            'revenue_multi_currency' => $multiCurrency,
            'revenues' => collect($revenueByCurrency)->map(fn ($amount, $currency) => [
                'currency' => $currency,
                'amount' => (float) $amount,
            ])->values()->all(),
            'revenue' => [
                'currency' => $primaryCurrency,
                'amount' => $primaryAmount ?? 0.0,
                'displayable' => ! $multiCurrency,
            ],
            'conversion_rate' => $this->rate((int) $purchases, (int) $unique),
            'aov' => $multiCurrency ? null : $this->aov($primaryAmount ?? 0.0, (int) $purchases),
            'best_platform' => $bestPlatform['platform'] ?? null,
            'attribution_model' => $filters->attributionModel,
        ];
    }

    /**
     * @param  list<array{currency: string, amount: float}>  $revenues
     * @return array{currency: string, amount: float, displayable: bool}
     */
    private function primaryRevenue(array $revenues): array
    {
        if ($revenues === []) {
            return ['currency' => 'EGP', 'amount' => 0.0, 'displayable' => true];
        }
        if (count($revenues) > 1) {
            return ['currency' => 'MIXED', 'amount' => 0.0, 'displayable' => false];
        }

        return [
            'currency' => $revenues[0]['currency'],
            'amount' => (float) $revenues[0]['amount'],
            'displayable' => true,
        ];
    }

    private function rate(int $purchases, int $uniqueClicks): ?float
    {
        if ($uniqueClicks <= 0) {
            return null;
        }

        return round(($purchases / $uniqueClicks) * 100, 2);
    }

    private function aov(float $revenue, int $purchases): ?float
    {
        if ($purchases <= 0) {
            return null;
        }

        return round($revenue / $purchases, 2);
    }

    private function jsonText(string $column, string $key): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "json_extract({$column}, '$.{$key}')"
            : "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$key}'))";
    }

    private function jsonInt(string $column, string $key): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "CAST(json_extract({$column}, '$.{$key}') AS INTEGER)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.{$key}')) AS UNSIGNED)";
    }

    private function dateExpr(string $column): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', {$column})"
            : "DATE({$column})";
    }
}
