<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CertificateTemplateResource\Pages;
use App\Modules\Certification\Infrastructure\Persistence\Models\CertificateTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CertificateTemplateResource extends Resource
{
    protected static ?string $model = CertificateTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Learning';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('name.ar')->label('Name (AR)')->required(),
            Forms\Components\TextInput::make('name.en')->label('Name (EN)'),
            Forms\Components\TextInput::make('background_path')->label('Background path'),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('name')->formatStateUsing(fn ($record) => $record->getTranslation('name', 'ar')),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCertificateTemplates::route('/'),
            'create' => Pages\CreateCertificateTemplate::route('/create'),
            'edit' => Pages\EditCertificateTemplate::route('/{record}/edit'),
        ];
    }
}
