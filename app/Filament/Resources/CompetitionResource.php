<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CompetitionResource\Pages;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CompetitionResource extends Resource
{
    protected static ?string $model = Competition::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?int $navigationSort = 0;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.competition');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.competitions');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.competitions');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.competitions');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')
                ->label(__('admin.competitions.fields.slug'))
                ->required()
                ->unique(ignoreRecord: true)
                ->extraInputAttributes(['dir' => 'ltr']),
            Forms\Components\TextInput::make('title.ar')->label(__('admin.competitions.fields.title_ar'))->required(),
            Forms\Components\TextInput::make('title.en')->label(__('admin.competitions.fields.title_en')),
            Forms\Components\Select::make('status')
                ->label(__('admin.competitions.fields.status'))
                ->options([
                    'draft' => __('admin.competitions.status.draft'),
                    'active' => __('admin.competitions.status.active'),
                    'judging' => __('admin.competitions.status.judging'),
                    'completed' => __('admin.competitions.status.completed'),
                    'archived' => __('admin.competitions.status.archived'),
                ])
                ->required(),
            Forms\Components\TextInput::make('required_photos')
                ->label(__('admin.competitions.fields.required_photos'))
                ->numeric()
                ->default(100),
            Forms\Components\DateTimePicker::make('starts_at')->label(__('admin.competitions.fields.starts_at'))->required(),
            Forms\Components\DateTimePicker::make('ends_at')->label(__('admin.competitions.fields.ends_at'))->required(),
            Forms\Components\TextInput::make('prize_amount')->label(__('admin.competitions.fields.prize_amount'))->numeric(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')->label(__('admin.common.fields.slug'))->searchable(),
                Tables\Columns\TextColumn::make('title')->label(__('admin.common.fields.title'))->formatStateUsing(fn ($record) => $record->getTranslation('title', 'ar')),
                Tables\Columns\TextColumn::make('status')->label(__('admin.common.fields.status'))->badge(),
                Tables\Columns\TextColumn::make('required_photos')->label(__('admin.competitions.fields.required_photos')),
                Tables\Columns\TextColumn::make('ends_at')->label(__('admin.competitions.fields.ends_at'))->dateTime(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompetitions::route('/'),
            'edit' => Pages\EditCompetition::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
