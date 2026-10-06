<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\SocialCampaignResource;
use App\Models\User;
use App\Modules\SocialAttribution\Application\Services\Analytics\AnalyticsFilters;
use App\Modules\SocialAttribution\Application\Services\Analytics\SocialAnalyticsService;
use App\Modules\SocialAttribution\Domain\Enums\AttributionPlatform;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @property Form $form
 */
class SocialAnalyticsDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 24;

    protected static string $view = 'filament.pages.social-analytics-dashboard';

    protected static ?string $slug = 'social-analytics';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /** @var array<string, mixed> */
    public array $dashboard = [];

    public string $campaignSearch = '';

    public string $campaignSort = 'revenue';

    public string $campaignSortDir = 'desc';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.social_commerce');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('admin.nav.social_analytics');
    }

    public function getTitle(): string
    {
        return (string) __('admin.social_analytics.title');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->canAccessPanel(filament()->getCurrentPanel());
    }

    public function mount(): void
    {
        $this->form->fill([
            'range' => '30d',
            'platform' => 'all',
            'attribution_model' => 'last_touch',
            'custom_from' => null,
            'custom_to' => null,
        ]);
        $this->refreshDashboard();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('range')
                    ->label(__('admin.social_analytics.filters.range'))
                    ->options([
                        'today' => __('admin.social_analytics.ranges.today'),
                        '7d' => __('admin.social_analytics.ranges.7d'),
                        '30d' => __('admin.social_analytics.ranges.30d'),
                        'month' => __('admin.social_analytics.ranges.month'),
                        'custom' => __('admin.social_analytics.ranges.custom'),
                        'all' => __('admin.social_analytics.ranges.all'),
                    ])
                    ->live()
                    ->afterStateUpdated(fn () => $this->refreshDashboard()),
                Forms\Components\DatePicker::make('custom_from')
                    ->label(__('admin.social_analytics.filters.from'))
                    ->visible(fn (Forms\Get $get): bool => $get('range') === 'custom')
                    ->live()
                    ->afterStateUpdated(fn () => $this->refreshDashboard()),
                Forms\Components\DatePicker::make('custom_to')
                    ->label(__('admin.social_analytics.filters.to'))
                    ->visible(fn (Forms\Get $get): bool => $get('range') === 'custom')
                    ->live()
                    ->afterStateUpdated(fn () => $this->refreshDashboard()),
                Forms\Components\Select::make('platform')
                    ->label(__('admin.social_analytics.filters.platform'))
                    ->options([
                        'all' => __('admin.social_analytics.filters.all_platforms'),
                        AttributionPlatform::Instagram->value => 'Instagram',
                        AttributionPlatform::Facebook->value => 'Facebook',
                        AttributionPlatform::YouTube->value => 'YouTube',
                        AttributionPlatform::TikTok->value => 'TikTok',
                    ])
                    ->live()
                    ->afterStateUpdated(fn () => $this->refreshDashboard()),
                Forms\Components\Select::make('attribution_model')
                    ->label(__('admin.social_analytics.filters.attribution_model'))
                    ->options([
                        'last_touch' => __('admin.social_analytics.filters.last_touch'),
                        'first_touch' => __('admin.social_analytics.filters.first_touch'),
                    ])
                    ->live()
                    ->afterStateUpdated(fn () => $this->refreshDashboard()),
            ])
            ->columns(5)
            ->statePath('data');
    }

    public function refreshDashboard(): void
    {
        $state = $this->form->getState();

        $filters = AnalyticsFilters::fromRange(
            (string) ($state['range'] ?? '30d'),
            $state['platform'] ?? 'all',
            (string) ($state['attribution_model'] ?? 'last_touch'),
            isset($state['custom_from']) ? (string) $state['custom_from'] : null,
            isset($state['custom_to']) ? (string) $state['custom_to'] : null,
        );

        $this->dashboard = app(SocialAnalyticsService::class)->dashboard($filters);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSortedCampaignsProperty(): array
    {
        $rows = $this->dashboard['campaigns'] ?? [];
        $q = trim(mb_strtolower($this->campaignSearch));
        if ($q !== '') {
            $rows = array_values(array_filter($rows, function (array $row) use ($q): bool {
                $hay = mb_strtolower(($row['name'] ?? '').' '.($row['code'] ?? ''));

                return str_contains($hay, $q);
            }));
        }

        $sort = $this->campaignSort;
        $dir = $this->campaignSortDir === 'asc' ? 1 : -1;
        usort($rows, function (array $a, array $b) use ($sort, $dir): int {
            $av = match ($sort) {
                'raw_clicks' => (int) ($a['raw_clicks'] ?? 0),
                'purchases' => (int) ($a['purchases'] ?? 0),
                'conversion_rate' => (float) ($a['conversion_rate'] ?? 0),
                default => (float) ($a['revenue']['amount'] ?? 0),
            };
            $bv = match ($sort) {
                'raw_clicks' => (int) ($b['raw_clicks'] ?? 0),
                'purchases' => (int) ($b['purchases'] ?? 0),
                'conversion_rate' => (float) ($b['conversion_rate'] ?? 0),
                default => (float) ($b['revenue']['amount'] ?? 0),
            };

            return $av <=> $bv ? (($av <=> $bv) * $dir) : 0;
        });

        return $rows;
    }

    public function sortCampaigns(string $column): void
    {
        if ($this->campaignSort === $column) {
            $this->campaignSortDir = $this->campaignSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->campaignSort = $column;
            $this->campaignSortDir = 'desc';
        }
    }

    public function campaignUrl(int $campaignId): string
    {
        return SocialCampaignResource::getUrl('edit', ['record' => $campaignId]);
    }

    public function exportCampaignsCsv(): StreamedResponse
    {
        return $this->exportTableCsv('campaigns', [
            'name', 'code', 'platform', 'channel_kind', 'raw_clicks', 'unique_clicks',
            'purchases', 'revenue_amount', 'revenue_currency', 'conversion_rate', 'aov',
        ]);
    }

    public function exportContentsCsv(): StreamedResponse
    {
        return $this->exportTableCsv('contents', [
            'title', 'content_type', 'platform', 'campaign_name', 'raw_clicks', 'unique_clicks',
            'purchases', 'revenue_amount', 'revenue_currency', 'conversion_rate',
        ]);
    }

    public function exportLinksCsv(): StreamedResponse
    {
        return $this->exportTableCsv('tracking_links', [
            'code', 'tracking_url', 'platform', 'campaign_name', 'content_title', 'destination_path',
            'raw_clicks', 'unique_clicks', 'purchases', 'revenue_amount', 'revenue_currency', 'conversion_rate',
        ]);
    }

    /**
     * @param  list<string>  $columns
     */
    private function exportTableCsv(string $key, array $columns): StreamedResponse
    {
        $this->refreshDashboard();
        $rows = $this->dashboard[$key] ?? [];

        return response()->streamDownload(function () use ($rows, $columns): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $columns);
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $col) {
                    if ($col === 'revenue_amount') {
                        $line[] = $row['revenue']['amount'] ?? 0;
                    } elseif ($col === 'revenue_currency') {
                        $line[] = $row['revenue']['currency'] ?? 'EGP';
                    } else {
                        $line[] = $row[$col] ?? '';
                    }
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, 'social-analytics-'.$key.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function formatMoney(?array $revenue): string
    {
        if ($revenue === null) {
            return '—';
        }
        if (($revenue['displayable'] ?? true) === false) {
            return (string) __('admin.social_analytics.multi_currency');
        }
        $amount = number_format((float) ($revenue['amount'] ?? 0), 2);
        $currency = (string) ($revenue['currency'] ?? 'EGP');

        return $amount.' '.$currency;
    }

    public function formatRate(?float $rate): string
    {
        return $rate === null ? '—' : number_format($rate, 2).'%';
    }

    public function formatAov(?float $aov, ?array $revenue = null): string
    {
        if ($aov === null) {
            return '—';
        }
        $currency = (string) ($revenue['currency'] ?? 'EGP');

        return number_format($aov, 2).' '.$currency;
    }

    public function platformLabel(string $platform): string
    {
        return match ($platform) {
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'youtube' => 'YouTube',
            'tiktok' => 'TikTok',
            'google_ads' => 'Google Ads',
            'organic' => (string) __('admin.social_analytics.platforms.organic'),
            default => (string) __('admin.social_analytics.platforms.other'),
        };
    }

    public function platformCardClass(string $platform): string
    {
        return match ($platform) {
            'instagram' => 'ig',
            'facebook' => 'fb',
            'youtube' => 'yt',
            'tiktok' => 'tt',
            default => 'other',
        };
    }

    public function platformIconLabel(string $platform): string
    {
        return match ($platform) {
            'instagram' => 'IG',
            'facebook' => 'FB',
            'youtube' => 'YT',
            'tiktok' => 'TT',
            default => '••',
        };
    }

    public function viewsDisplay(bool $available, mixed $views): string
    {
        if (! $available) {
            return (string) __('admin.social_analytics.views_unavailable');
        }

        return (string) (int) $views;
    }

    public function landingViewsDisplay(bool $available, mixed $views): string
    {
        if (! $available) {
            return (string) __('admin.social_analytics.landing_views_unavailable');
        }

        return (string) (int) $views;
    }
}
