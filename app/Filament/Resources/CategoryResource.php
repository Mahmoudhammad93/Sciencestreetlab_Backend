<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.catalog');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.categories');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.categories');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.categories');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.categories.sections.general'))->schema([
                Forms\Components\TextInput::make('name.en')
                    ->label(__('admin.common.fields.name_en'))
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, callable $set, Get $get): void {
                        if (filled($get('slug')) || blank($state)) {
                            return;
                        }

                        $set('slug', Str::slug($state));
                    }),
                Forms\Components\TextInput::make('name.ar')
                    ->label(__('admin.common.fields.name_ar'))
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText(__('admin.categories.fields.slug_help')),
                Forms\Components\TextInput::make('sort_order')
                    ->label(__('admin.common.fields.order'))
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->required(),
                Forms\Components\Toggle::make('is_active')
                    ->label(__('admin.categories.table.active'))
                    ->default(true)
                    ->helperText(__('admin.categories.fields.active_help')),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.categories.sections.description'))->schema([
                Forms\Components\Textarea::make('description.en')
                    ->label(__('admin.common.fields.description_en'))
                    ->rows(4),
                Forms\Components\Textarea::make('description.ar')
                    ->label(__('admin.common.fields.description_ar'))
                    ->rows(4),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.categories.sections.image'))->schema([
                SpatieMediaLibraryFileUpload::make('category_image')
                    ->collection('image')
                    ->label(__('admin.categories.fields.image'))
                    ->image()
                    ->maxFiles(1)
                    ->panelLayout('integrated')
                    ->imagePreviewHeight('220')
                    ->maxSize(5120)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->helperText(__('admin.categories.fields.image_help'))
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('category_image')
                    ->collection('image')
                    ->label(__('admin.categories.table.image'))
                    ->square(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.categories.table.name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('admin.common.fields.order'))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('admin.categories.table.active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label(__('admin.categories.table.products')),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label(__('admin.categories.table.active')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
