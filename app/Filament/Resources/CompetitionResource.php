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

    protected static ?string $navigationGroup = 'Competition';

    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('title.ar')->label('Title (AR)')->required(),
            Forms\Components\TextInput::make('title.en')->label('Title (EN)'),
            Forms\Components\Select::make('status')
                ->options([
                    'draft' => 'Draft',
                    'active' => 'Active',
                    'judging' => 'Judging',
                    'completed' => 'Completed',
                    'archived' => 'Archived',
                ])
                ->required(),
            Forms\Components\TextInput::make('required_photos')->numeric()->default(100),
            Forms\Components\DateTimePicker::make('starts_at')->required(),
            Forms\Components\DateTimePicker::make('ends_at')->required(),
            Forms\Components\TextInput::make('prize_amount')->numeric(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('title')->formatStateUsing(fn ($record) => $record->getTranslation('title', 'ar')),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('required_photos'),
                Tables\Columns\TextColumn::make('ends_at')->dateTime(),
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
