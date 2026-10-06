<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TrackingLinkResource\Pages;
use App\Modules\SocialAttribution\Application\Services\DestinationAllowlist;
use App\Modules\SocialAttribution\Domain\Enums\AttributionPlatform;
use App\Modules\SocialAttribution\Domain\Enums\CampaignStatus;
use App\Modules\SocialAttribution\Domain\Enums\DestinationType;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\SocialCampaign;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\SocialContent;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\TrackingLink;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TrackingLinkResource extends Resource
{
    protected static ?string $model = TrackingLink::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?int $navigationSort = 23;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.social_commerce');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.tracking_links');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.tracking_links');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\Select::make('platform')
                        ->options(collect(AttributionPlatform::cases())->mapWithKeys(
                            fn (AttributionPlatform $p) => [$p->value => $p->label()]
                        ))
                        ->required()
                        ->live(),
                    Forms\Components\Select::make('campaign_id')
                        ->options(fn (Get $get) => SocialCampaign::query()
                            ->when($get('platform'), fn ($q, $platform) => $q->where('platform', $platform))
                            ->where('status', '!=', CampaignStatus::Archived->value)
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->live(),
                    Forms\Components\Select::make('social_content_id')
                        ->label('Content')
                        ->options(fn (Get $get) => SocialContent::query()
                            ->when($get('platform'), fn ($q, $platform) => $q->where('platform', $platform))
                            ->when($get('campaign_id'), fn ($q, $campaignId) => $q->where('campaign_id', $campaignId))
                            ->where('status', '!=', CampaignStatus::Archived->value)
                            ->orderBy('title')
                            ->pluck('title', 'id'))
                        ->searchable()
                        ->nullable(),
                    Forms\Components\TextInput::make('code')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(64)
                        ->helperText('Public path: /go/{code}')
                        ->dehydrateStateUsing(fn (?string $state) => strtolower(Str::slug((string) $state, '-'))),
                    Forms\Components\TextInput::make('label')->maxLength(255),
                    Forms\Components\Select::make('destination_type')
                        ->options(collect(DestinationType::cases())->mapWithKeys(
                            fn (DestinationType $t) => [$t->value => $t->value]
                        ))
                        ->required(),
                    Forms\Components\TextInput::make('destination_id')->numeric()->nullable(),
                    Forms\Components\TextInput::make('destination_path')
                        ->required()
                        ->maxLength(500),
                    Forms\Components\Toggle::make('is_enabled')->default(true),
                    Forms\Components\Select::make('status')
                        ->options(collect(CampaignStatus::cases())->mapWithKeys(
                            fn (CampaignStatus $s) => [$s->value => ucfirst($s->value)]
                        ))
                        ->required()
                        ->default(CampaignStatus::Active->value),
                    Forms\Components\DateTimePicker::make('expires_at'),
                    Forms\Components\TextInput::make('utm_source')
                        ->maxLength(191)
                        ->helperText('Defaults to platform value (e.g. tiktok) when left empty.'),
                    Forms\Components\TextInput::make('utm_medium')
                        ->maxLength(191)
                        ->helperText('Defaults to social when left empty.'),
                    Forms\Components\TextInput::make('utm_campaign')->maxLength(191),
                    Forms\Components\TextInput::make('utm_content')->maxLength(191),
                    Forms\Components\TextInput::make('utm_term')->maxLength(191),
                    Forms\Components\Placeholder::make('preview')
                        ->label('Tracking URL preview')
                        ->content(function (Get $get): string {
                            $code = strtolower(Str::slug((string) $get('code'), '-'));
                            $base = rtrim((string) config('app.url'), '/');

                            return $code !== '' ? "{$base}/go/{$code}" : '—';
                        }),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('tracking_url')
                    ->label('Tracking URL')
                    ->state(fn (TrackingLink $record): string => url('/go/'.$record->code))
                    ->copyable()
                    ->copyMessage('Tracking URL copied')
                    ->limit(48)
                    ->tooltip(fn (TrackingLink $record): string => url('/go/'.$record->code)),
                Tables\Columns\TextColumn::make('platform')->badge(),
                Tables\Columns\TextColumn::make('campaign.name')->label('Campaign')->toggleable(),
                Tables\Columns\TextColumn::make('content.title')->label('Content')->toggleable(),
                Tables\Columns\TextColumn::make('destination_path')->label('Destination')->limit(40),
                Tables\Columns\IconColumn::make('is_enabled')->boolean(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('archive')
                    ->visible(fn (TrackingLink $record) => $record->status !== CampaignStatus::Archived)
                    ->action(fn (TrackingLink $record) => $record->update([
                        'status' => CampaignStatus::Archived,
                        'is_enabled' => false,
                    ])),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrackingLinks::route('/'),
            'create' => Pages\CreateTrackingLink::route('/create'),
            'edit' => Pages\EditTrackingLink::route('/{record}/edit'),
        ];
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function normalize(array $data): array
    {
        /** @var DestinationAllowlist $allowlist */
        $allowlist = app(DestinationAllowlist::class);
        try {
            $data['destination_path'] = $allowlist->assertSafePath((string) ($data['destination_path'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'destination_path' => $e->getMessage(),
            ]);
        }

        if (! empty($data['campaign_id'])) {
            $campaign = SocialCampaign::query()->find($data['campaign_id']);
            if ($campaign && ($data['platform'] ?? null) !== $campaign->platform->value) {
                throw ValidationException::withMessages([
                    'campaign_id' => 'Campaign platform must match tracking link platform.',
                ]);
            }
        }

        if (! empty($data['social_content_id'])) {
            $content = SocialContent::query()->find($data['social_content_id']);
            if ($content && ($data['platform'] ?? null) !== $content->platform->value) {
                throw ValidationException::withMessages([
                    'social_content_id' => 'Content platform must match tracking link platform.',
                ]);
            }
        }

        $platform = is_string($data['platform'] ?? null) ? $data['platform'] : null;
        if ($platform !== null && (! isset($data['utm_source']) || trim((string) $data['utm_source']) === '')) {
            $data['utm_source'] = $platform;
        }
        if (! isset($data['utm_medium']) || trim((string) $data['utm_medium']) === '') {
            $data['utm_medium'] = 'social';
        }

        return $data;
    }
}
