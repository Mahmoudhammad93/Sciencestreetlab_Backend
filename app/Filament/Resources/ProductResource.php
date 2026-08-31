<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('General')->schema([
                Forms\Components\TextInput::make('sku')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText('Used in the shop URL, e.g. science-street-microscope'),
                Forms\Components\Select::make('type')
                    ->options(collect(ProductType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                    ->required()
                    ->live(),
                Forms\Components\Select::make('status')
                    ->options(collect(ProductStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                    ->required()
                    ->default(ProductStatus::Draft->value)
                    ->live(),
                Forms\Components\Select::make('course_id')
                    ->label('Linked course')
                    ->options(fn (): array => Course::query()
                        ->orderBy('slug')
                        ->get()
                        ->mapWithKeys(fn (Course $course): array => [
                            $course->id => trim(($course->getTranslation('title', 'en') ?: $course->slug).' / '.($course->getTranslation('title', 'ar') ?: '')),
                        ])
                        ->all())
                    ->searchable()
                    ->nullable()
                    ->visible(fn (Get $get): bool => in_array($get('type'), [ProductType::Kit->value, ProductType::Bundle->value], true))
                    ->helperText('Optional. Links this product to a course for enrollment after purchase.'),
                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                Forms\Components\Toggle::make('is_featured'),
                Forms\Components\DateTimePicker::make('published_at')
                    ->visible(fn (Get $get): bool => $get('status') === ProductStatus::Published->value),
            ])->columns(2),
            Forms\Components\Section::make('Product image')->schema([
                SpatieMediaLibraryFileUpload::make('product_image')
                    ->collection('image')
                    ->label('Product image')
                    ->image()
                    ->maxFiles(1)
                    ->panelLayout('integrated')
                    ->imagePreviewHeight('220')
                    ->imageEditor()
                    ->imageEditorAspectRatios([
                        null,
                        '16:9',
                        '4:3',
                        '1:1',
                    ])
                    ->maxSize(5120)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->helperText('Drag and drop an image here, or click to browse. Shown in the shop catalog and product page.')
                    ->columnSpanFull(),
            ]),
            Forms\Components\Section::make('Pricing & inventory')->schema([
                Forms\Components\TextInput::make('price')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->prefix('EGP'),
                Forms\Components\TextInput::make('compare_price')
                    ->label('Compare at price')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('EGP')
                    ->helperText('Original price shown crossed out when on sale.'),
                Forms\Components\Select::make('currency')
                    ->options(['EGP' => 'EGP', 'KWD' => 'KWD', 'USD' => 'USD'])
                    ->default('EGP')
                    ->required(),
                Forms\Components\TextInput::make('stock_quantity')
                    ->numeric()
                    ->minValue(0),
                Forms\Components\Toggle::make('manage_stock')
                    ->live(),
            ])->columns(2),
            Forms\Components\Section::make('Arabic content')->schema([
                Forms\Components\TextInput::make('name.ar')
                    ->label('Name (AR)')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, callable $set, Get $get): void {
                        if (filled($get('slug')) || blank($state)) {
                            return;
                        }

                        $set('slug', Str::slug($state));
                    }),
                Forms\Components\Textarea::make('short_description.ar')
                    ->label('Short description (AR)')
                    ->rows(3),
                Forms\Components\Textarea::make('description.ar')
                    ->label('Full description (AR)')
                    ->rows(5),
            ]),
            Forms\Components\Section::make('English content')->schema([
                Forms\Components\TextInput::make('name.en')
                    ->label('Name (EN)')
                    ->maxLength(255),
                Forms\Components\Textarea::make('short_description.en')
                    ->label('Short description (EN)')
                    ->rows(3),
                Forms\Components\Textarea::make('description.en')
                    ->label('Full description (EN)')
                    ->rows(5),
            ]),
            Forms\Components\Section::make('SEO')->schema([
                Forms\Components\TextInput::make('meta_title.ar')->label('Meta title (AR)')->maxLength(255),
                Forms\Components\TextInput::make('meta_title.en')->label('Meta title (EN)')->maxLength(255),
                Forms\Components\Textarea::make('meta_description.ar')->label('Meta description (AR)')->rows(2),
                Forms\Components\Textarea::make('meta_description.en')->label('Meta description (EN)')->rows(2),
            ])->columns(2)->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('product_image')
                    ->collection('image')
                    ->label('Image')
                    ->square(),
                Tables\Columns\TextColumn::make('sku')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('price')->money('EGP')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('course.slug')->label('Course')->toggleable(),
                Tables\Columns\IconColumn::make('is_featured')->boolean(),
                Tables\Columns\TextColumn::make('published_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(collect(ProductType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(ProductStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
