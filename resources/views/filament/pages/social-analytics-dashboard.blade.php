@php
    /** @var \App\Filament\Pages\SocialAnalyticsDashboard $this */
    $d = $this->dashboard;
    $kpis = $d['kpis'] ?? [];
    $platforms = $d['platforms'] ?? [];
    $contents = $d['contents'] ?? [];
    $links = $d['tracking_links'] ?? [];
    $trend = $d['trend'] ?? [];
    $unattributed = $d['unattributed'] ?? ['purchases' => 0, 'revenues' => []];
    $empty = (bool) ($d['empty'] ?? true);
    $campaigns = $this->sortedCampaigns;
    $maxRev = max(1, ...array_map(fn ($p) => (float) ($p['revenue']['amount'] ?? 0), $platforms ?: [['revenue' => ['amount' => 0]]]));
    $maxPurch = max(1, ...array_map(fn ($p) => (int) ($p['purchases'] ?? 0), $platforms ?: [['purchases' => 0]]));
    $maxClick = max(1, ...array_map(fn ($p) => (int) ($p['unique_clicks'] ?? 0), $platforms ?: [['unique_clicks' => 0]]));
    $maxTrend = max(1, ...array_map(fn ($t) => max((int) ($t['unique_clicks'] ?? 0), (int) ($t['purchases'] ?? 0), (float) ($t['revenue'] ?? 0)), $trend ?: [['unique_clicks' => 0]]));
@endphp

<x-filament-panels::page>
    <style>
        .sa-page { --sa-ink:#1a1a2e; --sa-muted:#64748b; --sa-line:rgba(148,163,184,.28); --sa-surface:#fff; --sa-soft:#f8fafc; --sa-navy:#2828a0; --sa-ig:#E1306C; --sa-fb:#1877F2; --sa-yt:#FF0000; --sa-tt:#010101; }
        .dark .sa-page { --sa-ink:#f8fafc; --sa-muted:#94a3b8; --sa-line:rgba(148,163,184,.18); --sa-surface:rgba(15,23,42,.55); --sa-soft:rgba(30,41,59,.65); }
        .sa-page { color: var(--sa-ink); display:flex; flex-direction:column; gap:1.25rem; }
        .sa-filters { border:1px solid var(--sa-line); border-radius:1rem; background:var(--sa-surface); padding:1rem 1.1rem; }
        .sa-kpis { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.85rem; }
        @media (min-width:1100px){ .sa-kpis{ grid-template-columns:repeat(6,minmax(0,1fr)); } }
        @media (max-width:640px){ .sa-kpis{ grid-template-columns:1fr 1fr; } }
        .sa-kpi { border:1px solid var(--sa-line); border-radius:1rem; background:var(--sa-surface); padding:.95rem 1rem; min-height:5.25rem; }
        .sa-kpi__label { color:var(--sa-muted); font-size:.78rem; font-weight:600; margin-bottom:.35rem; }
        .sa-kpi__value { font-size:1.35rem; font-weight:700; letter-spacing:-.02em; line-height:1.2; }
        .sa-kpi__hint { color:var(--sa-muted); font-size:.72rem; margin-top:.3rem; }
        .sa-platforms { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.85rem; }
        @media (min-width:1100px){ .sa-platforms{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
        @media (max-width:640px){ .sa-platforms{ grid-template-columns:1fr; } }
        .sa-card { border:1px solid var(--sa-line); border-radius:1rem; background:var(--sa-surface); padding:1.1rem 1.15rem; position:relative; overflow:hidden; }
        .sa-card::before { content:''; position:absolute; inset-inline-start:0; top:0; bottom:0; width:4px; background:var(--accent,#2828a0); }
        .sa-card--ig { --accent: var(--sa-ig); }
        .sa-card--fb { --accent: var(--sa-fb); }
        .sa-card--yt { --accent: var(--sa-yt); }
        .sa-card--tt { --accent: #25F4EE; }
        .sa-card__title { display:flex; align-items:center; gap:.55rem; font-weight:700; margin-bottom:.85rem; }
        .sa-card__icon { width:1.65rem; height:1.65rem; border-radius:.5rem; display:grid; place-items:center; color:#fff; font-size:.7rem; font-weight:800; }
        .sa-card--ig .sa-card__icon { background: linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888); }
        .sa-card--fb .sa-card__icon { background:#1877F2; }
        .sa-card--yt .sa-card__icon { background:#FF0000; }
        .sa-card--tt .sa-card__icon { background: linear-gradient(135deg,#25F4EE,#FE2C55,#000); }
        .sa-metrics { display:grid; grid-template-columns:1fr 1fr; gap:.55rem .75rem; }
        .sa-metric span { display:block; color:var(--sa-muted); font-size:.72rem; }
        .sa-metric strong { font-size:.98rem; }
        .sa-section { border:1px solid var(--sa-line); border-radius:1rem; background:var(--sa-surface); overflow:hidden; }
        .sa-section__head { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:.75rem; padding:.9rem 1.1rem; border-bottom:1px solid var(--sa-line); }
        .sa-section__head h3 { margin:0; font-size:1rem; font-weight:700; }
        .sa-section__body { padding:1rem 1.1rem; }
        .sa-table-wrap { overflow-x:auto; }
        .sa-table { width:100%; border-collapse:collapse; font-size:.875rem; }
        .sa-table th, .sa-table td { padding:.65rem .7rem; border-bottom:1px solid var(--sa-line); text-align:start; white-space:nowrap; }
        .sa-table th { color:var(--sa-muted); font-size:.72rem; font-weight:700; text-transform:none; }
        .sa-table th button { background:none; border:0; color:inherit; font:inherit; cursor:pointer; padding:0; }
        .sa-table tr:hover td { background:var(--sa-soft); }
        .sa-muted { color:var(--sa-muted); }
        .sa-empty { text-align:center; padding:2rem 1rem; color:var(--sa-muted); }
        .sa-chip { display:inline-flex; align-items:center; gap:.25rem; border-radius:.5rem; background:var(--sa-soft); border:1px solid var(--sa-line); padding:.15rem .45rem; font-size:.75rem; }
        .sa-tooltip { cursor:help; border-bottom:1px dotted var(--sa-muted); }
        .sa-bars { display:flex; flex-direction:column; gap:.7rem; }
        .sa-bar-row { display:grid; grid-template-columns:6.5rem 1fr 4.5rem; gap:.65rem; align-items:center; }
        .sa-bar-track { height:.7rem; border-radius:999px; background:var(--sa-soft); border:1px solid var(--sa-line); overflow:hidden; }
        .sa-bar-fill { height:100%; border-radius:999px; background:var(--sa-navy); }
        .sa-bar-fill--ig { background:var(--sa-ig); }
        .sa-bar-fill--fb { background:var(--sa-fb); }
        .sa-bar-fill--yt { background:var(--sa-yt); }
        .sa-bar-fill--tt { background: linear-gradient(90deg,#25F4EE,#FE2C55); }
        .sa-charts { display:grid; grid-template-columns:1fr 1fr; gap:.85rem; }
        @media (max-width:900px){ .sa-charts{ grid-template-columns:1fr; } }
        .sa-trend { display:flex; align-items:flex-end; gap:.35rem; min-height:9rem; padding-top:.5rem; }
        .sa-trend__col { flex:1; display:flex; flex-direction:column; align-items:center; gap:.25rem; min-width:0; }
        .sa-trend__stack { width:100%; display:flex; align-items:flex-end; gap:2px; height:7rem; }
        .sa-trend__bar { flex:1; border-radius:3px 3px 0 0; min-height:2px; }
        .sa-trend__bar--c { background:#64748b; }
        .sa-trend__bar--p { background:#2828a0; }
        .sa-trend__bar--r { background:#059669; }
        .sa-trend__label { font-size:.62rem; color:var(--sa-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
        .sa-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
        .sa-search { border:1px solid var(--sa-line); border-radius:.65rem; background:transparent; padding:.4rem .65rem; min-width:12rem; color:inherit; }
        .sa-link { color:var(--sa-navy); text-decoration:none; font-weight:600; }
        .sa-link:hover { text-decoration:underline; }
        .sa-copy { font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:.78rem; }
        .sa-legend { display:flex; gap:1rem; flex-wrap:wrap; color:var(--sa-muted); font-size:.75rem; margin-bottom:.75rem; }
        .sa-legend i { display:inline-block; width:.65rem; height:.65rem; border-radius:2px; margin-inline-end:.3rem; }
    </style>

    <div class="sa-page" dir="rtl">
        <div class="sa-filters">
            {{ $this->form }}
        </div>

        @if($empty)
            <div class="sa-section">
                <div class="sa-empty">{{ __('admin.social_analytics.empty') }}</div>
            </div>
        @endif

        <div class="sa-kpis">
            <div class="sa-kpi">
                <div class="sa-kpi__label">{{ __('admin.social_analytics.kpis.raw_clicks') }}</div>
                <div class="sa-kpi__value">{{ number_format((int) ($kpis['raw_clicks'] ?? 0)) }}</div>
            </div>
            <div class="sa-kpi">
                <div class="sa-kpi__label">{{ __('admin.social_analytics.kpis.unique_clicks') }}</div>
                <div class="sa-kpi__value">{{ number_format((int) ($kpis['unique_clicks'] ?? 0)) }}</div>
            </div>
            <div class="sa-kpi">
                <div class="sa-kpi__label">{{ __('admin.social_analytics.kpis.purchases') }}</div>
                <div class="sa-kpi__value">{{ number_format((int) ($kpis['purchases'] ?? 0)) }}</div>
            </div>
            <div class="sa-kpi">
                <div class="sa-kpi__label">{{ __('admin.social_analytics.kpis.revenue') }}</div>
                <div class="sa-kpi__value">{{ $this->formatMoney($kpis['revenue'] ?? null) }}</div>
            </div>
            <div class="sa-kpi">
                <div class="sa-kpi__label">{{ __('admin.social_analytics.kpis.conversion_rate') }}</div>
                <div class="sa-kpi__value">{{ $this->formatRate($kpis['conversion_rate'] ?? null) }}</div>
            </div>
            <div class="sa-kpi">
                <div class="sa-kpi__label">{{ __('admin.social_analytics.kpis.aov') }}</div>
                <div class="sa-kpi__value">{{ $this->formatAov($kpis['aov'] ?? null, $kpis['revenue'] ?? null) }}</div>
                @if(!empty($kpis['best_platform']))
                    <div class="sa-kpi__hint">{{ __('admin.social_analytics.kpis.best_platform') }}: {{ $this->platformLabel($kpis['best_platform']) }}</div>
                @endif
            </div>
        </div>

        <div>
            <h3 style="margin:0 0 .75rem;font-size:1rem;font-weight:700;">{{ __('admin.social_analytics.sections.top_platforms') }}</h3>
            <div class="sa-platforms">
                @forelse($platforms as $p)
                    @php
                        $slug = $p['platform'];
                        $card = $this->platformCardClass($slug);
                    @endphp
                    <div class="sa-card sa-card--{{ $card }}">
                        <div class="sa-card__title">
                            <span class="sa-card__icon">{{ $this->platformIconLabel($slug) }}</span>
                            <span>{{ $this->platformLabel($slug) }}</span>
                        </div>
                        <div class="sa-metrics">
                            <div class="sa-metric">
                                <span title="{{ __('admin.social_analytics.views_tooltip') }}" class="sa-tooltip">{{ __('admin.social_analytics.platform_content_views') }}</span>
                                <strong>{{ $this->viewsDisplay((bool) $p['views_available'], $p['views']) }}</strong>
                            </div>
                            <div class="sa-metric">
                                <span title="{{ __('admin.social_analytics.landing_views_tooltip') }}" class="sa-tooltip">{{ __('admin.social_analytics.landing_page_views') }}</span>
                                <strong>{{ $this->landingViewsDisplay((bool) ($p['landing_page_views_available'] ?? false), $p['landing_page_views'] ?? null) }}</strong>
                            </div>
                            <div class="sa-metric">
                                <span>{{ __('admin.social_analytics.link_clicks') }}</span>
                                <strong>{{ number_format((int) $p['raw_clicks']) }}</strong>
                            </div>
                            <div class="sa-metric">
                                <span>{{ __('admin.social_analytics.columns.unique_clicks') }}</span>
                                <strong>{{ number_format((int) $p['unique_clicks']) }}</strong>
                            </div>
                            <div class="sa-metric">
                                <span>{{ __('admin.social_analytics.columns.purchases') }}</span>
                                <strong>{{ number_format((int) $p['purchases']) }}</strong>
                            </div>
                            <div class="sa-metric">
                                <span>{{ __('admin.social_analytics.columns.revenue') }}</span>
                                <strong>{{ $this->formatMoney($p['revenue'] ?? null) }}</strong>
                            </div>
                            <div class="sa-metric">
                                <span>{{ __('admin.social_analytics.columns.conversion_rate') }}</span>
                                <strong>{{ $this->formatRate($p['conversion_rate'] ?? null) }}</strong>
                            </div>
                            <div class="sa-metric">
                                <span>{{ __('admin.social_analytics.columns.aov') }}</span>
                                <strong>{{ $this->formatAov($p['aov'] ?? null, $p['revenue'] ?? null) }}</strong>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="sa-section" style="grid-column:1/-1"><div class="sa-empty">{{ __('admin.social_analytics.empty') }}</div></div>
                @endforelse
            </div>
        </div>

        @if(($unattributed['purchases'] ?? 0) > 0)
            <div class="sa-section">
                <div class="sa-section__body">
                    <strong>{{ __('admin.social_analytics.unattributed') }}:</strong>
                    {{ number_format((int) $unattributed['purchases']) }}
                    —
                    @foreach($unattributed['revenues'] as $rev)
                        {{ number_format((float) $rev['amount'], 2) }} {{ $rev['currency'] }}@if(!$loop->last), @endif
                    @endforeach
                </div>
            </div>
        @endif

        <div class="sa-charts">
            <div class="sa-section">
                <div class="sa-section__head"><h3>{{ __('admin.social_analytics.sections.revenue_by_platform') }}</h3></div>
                <div class="sa-section__body">
                    @if(collect($platforms)->sum(fn ($p) => (float) ($p['revenue']['amount'] ?? 0)) <= 0)
                        <div class="sa-empty">{{ __('admin.social_analytics.empty_chart') }}</div>
                    @else
                        <div class="sa-bars">
                            @foreach($platforms as $p)
                                <div class="sa-bar-row">
                                    <span>{{ $this->platformLabel($p['platform']) }}</span>
                                    <div class="sa-bar-track">
                                        <div class="sa-bar-fill sa-bar-fill--{{ $this->platformCardClass($p['platform']) }}" style="width:{{ min(100, (($p['revenue']['amount'] ?? 0) / $maxRev) * 100) }}%"></div>
                                    </div>
                                    <span>{{ $this->formatMoney($p['revenue'] ?? null) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            <div class="sa-section">
                <div class="sa-section__head"><h3>{{ __('admin.social_analytics.sections.purchases_by_platform') }}</h3></div>
                <div class="sa-section__body">
                    @if(collect($platforms)->sum(fn ($p) => (int) ($p['purchases'] ?? 0)) <= 0)
                        <div class="sa-empty">{{ __('admin.social_analytics.empty_chart') }}</div>
                    @else
                        <div class="sa-bars">
                            @foreach($platforms as $p)
                                <div class="sa-bar-row">
                                    <span>{{ $this->platformLabel($p['platform']) }}</span>
                                    <div class="sa-bar-track">
                                        <div class="sa-bar-fill sa-bar-fill--{{ $this->platformCardClass($p['platform']) }}" style="width:{{ min(100, (($p['purchases'] ?? 0) / $maxPurch) * 100) }}%"></div>
                                    </div>
                                    <span>{{ number_format((int) $p['purchases']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="sa-section">
            <div class="sa-section__head"><h3>{{ __('admin.social_analytics.sections.clicks_vs_purchases') }}</h3></div>
            <div class="sa-section__body">
                <div class="sa-legend">
                    <span><i style="background:#64748b"></i>{{ __('admin.social_analytics.columns.unique_clicks') }}</span>
                    <span><i style="background:#2828a0"></i>{{ __('admin.social_analytics.columns.purchases') }}</span>
                </div>
                <div class="sa-bars">
                    @foreach($platforms as $p)
                        <div class="sa-bar-row" style="grid-template-columns:6.5rem 1fr 1fr 3rem 3rem;">
                            <span>{{ $this->platformLabel($p['platform']) }}</span>
                            <div class="sa-bar-track"><div class="sa-bar-fill" style="background:#64748b;width:{{ min(100, (($p['unique_clicks'] ?? 0) / $maxClick) * 100) }}%"></div></div>
                            <div class="sa-bar-track"><div class="sa-bar-fill" style="width:{{ min(100, (($p['purchases'] ?? 0) / $maxPurch) * 100) }}%"></div></div>
                            <span>{{ (int) $p['unique_clicks'] }}</span>
                            <span>{{ (int) $p['purchases'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="sa-section">
            <div class="sa-section__head"><h3>{{ __('admin.social_analytics.sections.trend') }}</h3></div>
            <div class="sa-section__body">
                @if(count($trend) === 0)
                    <div class="sa-empty">{{ __('admin.social_analytics.empty_chart') }}</div>
                @else
                    <div class="sa-legend">
                        <span><i style="background:#64748b"></i>{{ __('admin.social_analytics.columns.unique_clicks') }}</span>
                        <span><i style="background:#2828a0"></i>{{ __('admin.social_analytics.columns.purchases') }}</span>
                        <span><i style="background:#059669"></i>{{ __('admin.social_analytics.columns.revenue') }}</span>
                    </div>
                    <div class="sa-trend">
                        @foreach($trend as $day)
                            <div class="sa-trend__col" title="{{ $day['date'] }}">
                                <div class="sa-trend__stack">
                                    <div class="sa-trend__bar sa-trend__bar--c" style="height:{{ max(2, (($day['unique_clicks'] ?? 0) / $maxTrend) * 100) }}%"></div>
                                    <div class="sa-trend__bar sa-trend__bar--p" style="height:{{ max(2, (($day['purchases'] ?? 0) / $maxTrend) * 100) }}%"></div>
                                    <div class="sa-trend__bar sa-trend__bar--r" style="height:{{ max(2, (($day['revenue'] ?? 0) / $maxTrend) * 100) }}%"></div>
                                </div>
                                <div class="sa-trend__label">{{ \Illuminate\Support\Str::of($day['date'])->substr(5) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="sa-section">
            <div class="sa-section__head">
                <h3>{{ __('admin.social_analytics.sections.campaigns') }}</h3>
                <div class="sa-actions">
                    <input type="search" class="sa-search" wire:model.live.debounce.300ms="campaignSearch" placeholder="{{ __('admin.social_analytics.search_campaign') }}" />
                    <x-filament::button size="sm" color="gray" wire:click="exportCampaignsCsv">CSV</x-filament::button>
                </div>
            </div>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th>{{ __('admin.social_analytics.columns.campaign') }}</th>
                            <th>{{ __('admin.social_analytics.columns.platform') }}</th>
                            <th>{{ __('admin.social_analytics.columns.channel_kind') }}</th>
                            <th><button type="button" wire:click="sortCampaigns('raw_clicks')">{{ __('admin.social_analytics.columns.raw_clicks') }}</button></th>
                            <th>{{ __('admin.social_analytics.columns.unique_clicks') }}</th>
                            <th><button type="button" wire:click="sortCampaigns('purchases')">{{ __('admin.social_analytics.columns.purchases') }}</button></th>
                            <th><button type="button" wire:click="sortCampaigns('revenue')">{{ __('admin.social_analytics.columns.revenue') }}</button></th>
                            <th><button type="button" wire:click="sortCampaigns('conversion_rate')">{{ __('admin.social_analytics.columns.conversion_rate') }}</button></th>
                            <th>{{ __('admin.social_analytics.columns.aov') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($campaigns as $row)
                            <tr>
                                <td>
                                    <a class="sa-link" href="{{ $this->campaignUrl((int) $row['campaign_id']) }}">{{ $row['name'] }}</a>
                                    @if(!empty($row['code']))
                                        <div class="sa-muted sa-copy">{{ $row['code'] }}</div>
                                    @endif
                                </td>
                                <td>{{ $row['platform'] ? $this->platformLabel($row['platform']) : '—' }}</td>
                                <td>{{ $row['channel_kind'] ?? '—' }}</td>
                                <td>{{ number_format((int) $row['raw_clicks']) }}</td>
                                <td>{{ number_format((int) $row['unique_clicks']) }}</td>
                                <td>{{ number_format((int) $row['purchases']) }}</td>
                                <td>{{ $this->formatMoney($row['revenue'] ?? null) }}</td>
                                <td>{{ $this->formatRate($row['conversion_rate'] ?? null) }}</td>
                                <td>{{ $this->formatAov($row['aov'] ?? null, $row['revenue'] ?? null) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="sa-empty">{{ __('admin.social_analytics.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="sa-section">
            <div class="sa-section__head">
                <h3>{{ __('admin.social_analytics.sections.contents') }}</h3>
                <x-filament::button size="sm" color="gray" wire:click="exportContentsCsv">CSV</x-filament::button>
            </div>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th>{{ __('admin.social_analytics.columns.content') }}</th>
                            <th>{{ __('admin.social_analytics.columns.type') }}</th>
                            <th>{{ __('admin.social_analytics.columns.platform') }}</th>
                            <th>{{ __('admin.social_analytics.columns.campaign') }}</th>
                            <th>{{ __('admin.social_analytics.columns.views') }}</th>
                            <th>{{ __('admin.social_analytics.columns.raw_clicks') }}</th>
                            <th>{{ __('admin.social_analytics.columns.unique_clicks') }}</th>
                            <th>{{ __('admin.social_analytics.columns.purchases') }}</th>
                            <th>{{ __('admin.social_analytics.columns.revenue') }}</th>
                            <th>{{ __('admin.social_analytics.columns.conversion_rate') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($contents as $row)
                            <tr>
                                <td>{{ $row['title'] }}</td>
                                <td>{{ $row['content_type'] ?? '—' }}</td>
                                <td>{{ $row['platform'] ? $this->platformLabel($row['platform']) : '—' }}</td>
                                <td>{{ $row['campaign_name'] ?? '—' }}</td>
                                <td><span class="sa-tooltip" title="{{ __('admin.social_analytics.views_tooltip') }}">{{ $this->viewsDisplay((bool) ($row['views_available'] ?? false), $row['views'] ?? null) }}</span></td>
                                <td>{{ number_format((int) $row['raw_clicks']) }}</td>
                                <td>{{ number_format((int) $row['unique_clicks']) }}</td>
                                <td>{{ number_format((int) $row['purchases']) }}</td>
                                <td>{{ $this->formatMoney($row['revenue'] ?? null) }}</td>
                                <td>{{ $this->formatRate($row['conversion_rate'] ?? null) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="sa-empty">{{ __('admin.social_analytics.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="sa-section">
            <div class="sa-section__head">
                <h3>{{ __('admin.social_analytics.sections.tracking_links') }}</h3>
                <x-filament::button size="sm" color="gray" wire:click="exportLinksCsv">CSV</x-filament::button>
            </div>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th>{{ __('admin.social_analytics.columns.tracking_link') }}</th>
                            <th>{{ __('admin.social_analytics.columns.platform') }}</th>
                            <th>{{ __('admin.social_analytics.columns.campaign') }}</th>
                            <th>{{ __('admin.social_analytics.columns.content') }}</th>
                            <th>{{ __('admin.social_analytics.columns.destination') }}</th>
                            <th>{{ __('admin.social_analytics.columns.raw_clicks') }}</th>
                            <th>{{ __('admin.social_analytics.columns.unique_clicks') }}</th>
                            <th>{{ __('admin.social_analytics.columns.purchases') }}</th>
                            <th>{{ __('admin.social_analytics.columns.revenue') }}</th>
                            <th>{{ __('admin.social_analytics.columns.conversion_rate') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($links as $row)
                            <tr>
                                <td>
                                    <div class="sa-copy">/go/{{ $row['code'] }}</div>
                                    @if(!empty($row['tracking_url']))
                                        <div class="sa-muted sa-copy">{{ $row['tracking_url'] }}</div>
                                    @endif
                                </td>
                                <td>{{ $row['platform'] ? $this->platformLabel($row['platform']) : '—' }}</td>
                                <td>{{ $row['campaign_name'] ?? '—' }}</td>
                                <td>{{ $row['content_title'] ?? '—' }}</td>
                                <td class="sa-copy">{{ $row['destination_path'] ?? '—' }}</td>
                                <td>{{ number_format((int) $row['raw_clicks']) }}</td>
                                <td>{{ number_format((int) $row['unique_clicks']) }}</td>
                                <td>{{ number_format((int) $row['purchases']) }}</td>
                                <td>{{ $this->formatMoney($row['revenue'] ?? null) }}</td>
                                <td>{{ $this->formatRate($row['conversion_rate'] ?? null) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="sa-empty">{{ __('admin.social_analytics.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="sa-section">
            <div class="sa-section__head"><h3>{{ __('admin.social_analytics.sections.platform_summary') }}</h3></div>
            <div class="sa-table-wrap">
                <table class="sa-table">
                    <thead>
                        <tr>
                            <th>{{ __('admin.social_analytics.columns.platform') }}</th>
                            <th>{{ __('admin.social_analytics.platform_content_views') }}</th>
                            <th>{{ __('admin.social_analytics.landing_page_views') }}</th>
                            <th>{{ __('admin.social_analytics.link_clicks') }}</th>
                            <th>{{ __('admin.social_analytics.columns.human_clicks') }}</th>
                            <th>{{ __('admin.social_analytics.columns.unique_clicks') }}</th>
                            <th>{{ __('admin.social_analytics.columns.purchases') }}</th>
                            <th>{{ __('admin.social_analytics.columns.revenue') }}</th>
                            <th>{{ __('admin.social_analytics.columns.conversion_rate') }}</th>
                            <th>{{ __('admin.social_analytics.columns.aov') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($platforms as $p)
                            <tr>
                                <td>{{ $this->platformLabel($p['platform']) }}</td>
                                <td><span class="sa-tooltip" title="{{ __('admin.social_analytics.views_tooltip') }}">{{ $this->viewsDisplay((bool) $p['views_available'], $p['views']) }}</span></td>
                                <td><span class="sa-tooltip" title="{{ __('admin.social_analytics.landing_views_tooltip') }}">{{ $this->landingViewsDisplay((bool) ($p['landing_page_views_available'] ?? false), $p['landing_page_views'] ?? null) }}</span></td>
                                <td>{{ number_format((int) $p['raw_clicks']) }}</td>
                                <td>{{ number_format((int) $p['human_clicks']) }}</td>
                                <td>{{ number_format((int) $p['unique_clicks']) }}</td>
                                <td>{{ number_format((int) $p['purchases']) }}</td>
                                <td>{{ $this->formatMoney($p['revenue'] ?? null) }}</td>
                                <td>{{ $this->formatRate($p['conversion_rate'] ?? null) }}</td>
                                <td>{{ $this->formatAov($p['aov'] ?? null, $p['revenue'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
