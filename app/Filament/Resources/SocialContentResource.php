<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SocialContentResource\Pages;
use App\Modules\SocialAttribution\Application\Services\DestinationAllowlist;
use App\Modules\SocialAttribution\Domain\Enums\AttributionPlatform;
use App\Modules\SocialAttribution\Domain\Enums\CampaignStatus;
use App\Modules\SocialAttribution\Domain\Enums\ContentType;
use App\Modules\SocialAttribution\Domain\Enums\DestinationType;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\SocialCampaign;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\SocialContent;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class SocialContentResource extends Resource
{
    protected static ?string $model = SocialContent::class;

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?int $navigationSort = 22;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.social_commerce');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.social_contents');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.social_contents');
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
                        ->label('Campaign')
                        ->options(fn (Get $get) => SocialCampaign::query()
                            ->when($get('platform'), fn ($q, $platform) => $q->where('platform', $platform))
                            ->where('status', '!=', CampaignStatus::Archived->value)
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->nullable(),
                    Forms\Components\Select::make('content_type')
                        ->options(collect(ContentType::cases())->mapWithKeys(
                            fn (ContentType $t) => [$t->value => $t->label()]
                        ))
                        ->required(),
                    Forms\Components\TextInput::make('title')->required()->maxLength(255),
                    Forms\Components\TextInput::make('external_content_id')->maxLength(191),
                    Forms\Components\TextInput::make('external_url')->maxLength(2048)->url()->nullable(),
                    Forms\Components\Select::make('status')
                        ->options(collect(CampaignStatus::cases())->mapWithKeys(
                            fn (CampaignStatus $s) => [$s->value => ucfirst($s->value)]
                        ))
                        ->required()
                        ->default(CampaignStatus::Active->value),
                    Forms\Components\DateTimePicker::make('published_at'),
                    Forms\Components\Select::make('destination_type')
                        ->options(collect(DestinationType::cases())->mapWithKeys(
                            fn (DestinationType $t) => [$t->value => $t->value]
                        ))
                        ->required()
                        ->default(DestinationType::InternalPath->value),
                    Forms\Components\TextInput::make('destination_id')
                        ->numeric()
                        ->nullable()
                        ->helperText('Product or Course id when typed destination is used.'),
                    Forms\Components\TextInput::make('destination_path')
                        ->required()
                        ->maxLength(500)
                        ->helperText('Internal path only, e.g. /shop/science-street-microscope-2 or /microscope-landing-page'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('platform')->badge(),
                Tables\Columns\TextColumn::make('campaign.name')->label('Campaign')->toggleable(),
                Tables\Columns\TextColumn::make('content_type')->label('Type'),
                Tables\Columns\TextColumn::make('destination_path')->label('Destination')->limit(40),
                Tables\Columns\TextColumn::make('status')->badge(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('archive')
                    ->visible(fn (SocialContent $record) => $record->status !== CampaignStatus::Archived)
                    ->action(fn (SocialContent $record) => $record->update(['status' => CampaignStatus::Archived])),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSocialContents::route('/'),
            'create' => Pages\CreateSocialContent::route('/create'),
            'edit' => Pages\EditSocialContent::route('/{record}/edit'),
        ];
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function normalizeDestination(array $data): array
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
                    'campaign_id' => 'Campaign platform must match content platform.',
                ]);
            }
        }

        return $data;
    }
}
