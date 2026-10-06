<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SocialCampaignResource\Pages;
use App\Modules\SocialAttribution\Domain\Enums\AttributionPlatform;
use App\Modules\SocialAttribution\Domain\Enums\CampaignStatus;
use App\Modules\SocialAttribution\Domain\Enums\ChannelKind;
use App\Modules\SocialAttribution\Infrastructure\Persistence\Models\SocialCampaign;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class SocialCampaignResource extends Resource
{
    protected static ?string $model = SocialCampaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 21;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.social_commerce');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.social_campaigns');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.social_campaigns');
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
                        ->required(),
                    Forms\Components\TextInput::make('name')->required()->maxLength(255),
                    Forms\Components\TextInput::make('code')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(80)
                        ->dehydrateStateUsing(fn (?string $state) => Str::slug((string) $state, '-')),
                    Forms\Components\Select::make('channel_kind')
                        ->options(collect(ChannelKind::cases())->mapWithKeys(
                            fn (ChannelKind $c) => [$c->value => ucfirst($c->value)]
                        ))
                        ->required()
                        ->default(ChannelKind::Organic->value),
                    Forms\Components\Select::make('status')
                        ->options(collect(CampaignStatus::cases())->mapWithKeys(
                            fn (CampaignStatus $s) => [$s->value => ucfirst($s->value)]
                        ))
                        ->required()
                        ->default(CampaignStatus::Active->value),
                    Forms\Components\TextInput::make('external_campaign_id')->maxLength(191),
                    Forms\Components\DateTimePicker::make('starts_at'),
                    Forms\Components\DateTimePicker::make('ends_at'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('code')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('platform')->badge(),
                Tables\Columns\TextColumn::make('channel_kind')->badge()->label('Channel'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('archive')
                    ->label(__('admin.social_attribution.archive'))
                    ->visible(fn (SocialCampaign $record) => $record->status !== CampaignStatus::Archived)
                    ->action(fn (SocialCampaign $record) => $record->update(['status' => CampaignStatus::Archived])),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSocialCampaigns::route('/'),
            'create' => Pages\CreateSocialCampaign::route('/create'),
            'edit' => Pages\EditSocialCampaign::route('/{record}/edit'),
        ];
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
