<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Forms\Components\ImageDropzone;
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

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.learning');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.certificate_templates');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.certificate_templates');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.certificate_templates');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.certificates.sections.template'))->schema([
                Forms\Components\TextInput::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\TextInput::make('name.ar')->label(__('admin.certificates.fields.name_ar'))->required(),
                Forms\Components\TextInput::make('name.en')->label(__('admin.certificates.fields.name_en')),
                Forms\Components\Toggle::make('is_active')->label(__('admin.common.fields.active'))->default(true),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.certificates.sections.background'))->schema([
                ImageDropzone::make(
                    'background_path',
                    'certificates',
                    __('admin.certificates.fields.background'),
                    __('admin.certificates.fields.background_help')
                ),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('background_path')
                    ->label(__('admin.certificates.table.background'))
                    ->getStateUsing(fn (CertificateTemplate $record): ?string => ImageDropzone::publicUrl($record->background_path)),
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.common.fields.name'))
                    ->formatStateUsing(fn ($record) => $record->getTranslation('name', 'ar')),
                Tables\Columns\IconColumn::make('is_active')->label(__('admin.common.fields.active'))->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->label(__('admin.common.fields.updated_at'))->dateTime(),
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
