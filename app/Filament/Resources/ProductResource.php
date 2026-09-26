<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
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

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.catalog');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.products');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.products');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.products');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.products.sections.general'))->schema([
                Forms\Components\TextInput::make('sku')
                    ->label(__('admin.common.fields.sku'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100)
                    ->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\TextInput::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->helperText(__('admin.products.fields.slug_help'))
                    ->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\Select::make('type')
                    ->label(__('admin.common.fields.type'))
                    ->options(collect(ProductType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                    ->required()
                    ->live(),
                Forms\Components\Select::make('status')
                    ->label(__('admin.common.fields.status'))
                    ->options(collect(ProductStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                    ->required()
                    ->default(ProductStatus::Draft->value)
                    ->live(),
                Forms\Components\Select::make('course_id')
                    ->label(__('admin.products.fields.linked_course'))
                    ->options(fn (): array => Course::query()
                        ->orderBy('slug')
                        ->get()
                        ->mapWithKeys(fn (Course $course): array => [
                            $course->id => trim(($course->getTranslation('title', 'en') ?: $course->slug).' / '.($course->getTranslation('title', 'ar') ?: '')),
                        ])
                        ->all())
                    ->searchable()
                    ->nullable()
                    ->live()
                    ->visible(fn (Get $get): bool => in_array($get('type'), [ProductType::Kit->value, ProductType::Bundle->value, ProductType::Course->value], true))
                    ->helperText(__('admin.products.fields.linked_course_help')),
                Forms\Components\Select::make('course_plan_id')
                    ->label(__('admin.products.fields.course_plan'))
                    ->options(fn (Get $get): array => CoursePlan::query()
                        ->where('course_id', $get('course_id'))
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn (CoursePlan $plan): array => [
                            $plan->id => ($plan->getTranslation('name', 'en') ?: $plan->id).' — '.$plan->price.' '.$plan->currency,
                        ])
                        ->all())
                    ->searchable()
                    ->nullable()
                    ->visible(fn (Get $get): bool => filled($get('course_id')))
                    ->helperText(__('admin.products.fields.course_plan_help')),
                Forms\Components\Select::make('category_id')
                    ->label(__('admin.products.fields.category'))
                    ->options(fn (): array => Category::query()
                        ->orderBy('sort_order')
                        ->orderBy('slug')
                        ->get()
                        ->mapWithKeys(fn (Category $category): array => [
                            $category->id => trim(($category->getTranslation('name', 'en') ?: $category->slug).' / '.($category->getTranslation('name', 'ar') ?: '')),
                        ])
                        ->all())
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText(__('admin.products.fields.category_help')),
                Forms\Components\TextInput::make('sort_order')
                    ->label(__('admin.common.fields.order'))
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                Forms\Components\Toggle::make('is_featured')
                    ->label(__('admin.products.fields.is_featured')),
                Forms\Components\DateTimePicker::make('published_at')
                    ->label(__('admin.products.fields.published_at'))
                    ->visible(fn (Get $get): bool => $get('status') === ProductStatus::Published->value),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.products.sections.product_image'))->schema([
                SpatieMediaLibraryFileUpload::make('product_image')
                    ->collection('image')
                    ->label(__('admin.products.fields.product_image'))
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
                    ->helperText(__('admin.products.fields.product_image_help'))
                    ->columnSpanFull(),
            ]),
            Forms\Components\Section::make(__('admin.products.sections.gallery'))->schema([
                SpatieMediaLibraryFileUpload::make('gallery')
                    ->collection('gallery')
                    ->label(__('admin.products.fields.gallery'))
                    ->image()
                    ->multiple()
                    ->reorderable()
                    ->panelLayout('grid')
                    ->imagePreviewHeight('160')
                    ->maxSize(5120)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->helperText(__('admin.products.fields.gallery_help'))
                    ->columnSpanFull(),
                SpatieMediaLibraryFileUpload::make('concept_images')
                    ->collection('concept_images')
                    ->label(__('admin.products.fields.concept_images'))
                    ->image()
                    ->multiple()
                    ->reorderable()
                    ->panelLayout('grid')
                    ->imagePreviewHeight('160')
                    ->maxSize(5120)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->helperText(__('admin.products.fields.concept_images_help'))
                    ->columnSpanFull(),
            ])->collapsed(),
            Forms\Components\Section::make(__('admin.products.sections.educational'))->schema([
                Forms\Components\TextInput::make('difficulty_level')
                    ->label(__('admin.products.fields.difficulty'))
                    ->maxLength(50)
                    ->placeholder(__('admin.products.fields.difficulty_placeholder')),
                Forms\Components\TextInput::make('target_age')
                    ->label(__('admin.products.fields.target_age'))
                    ->maxLength(100)
                    ->placeholder(__('admin.products.fields.target_age_placeholder')),
                Forms\Components\Select::make('related_course_id')
                    ->label(__('admin.products.fields.related_course'))
                    ->options(fn (): array => Course::query()
                        ->orderBy('slug')
                        ->get()
                        ->mapWithKeys(fn (Course $course): array => [
                            $course->id => trim(($course->getTranslation('title', 'en') ?: $course->slug).' / '.($course->getTranslation('title', 'ar') ?: '')),
                        ])
                        ->all())
                    ->searchable()
                    ->nullable(),
                Forms\Components\TagsInput::make('key_benefits')
                    ->label(__('admin.products.fields.key_benefits'))
                    ->placeholder(__('admin.products.fields.key_benefits_placeholder'))
                    ->columnSpanFull(),
                Forms\Components\TagsInput::make('scientific_concepts.en')
                    ->label(__('admin.products.fields.concepts_en'))
                    ->placeholder(__('admin.products.fields.concepts_en_placeholder'))
                    ->columnSpanFull(),
                Forms\Components\TagsInput::make('scientific_concepts.ar')
                    ->label(__('admin.products.fields.concepts_ar'))
                    ->placeholder(__('admin.products.fields.concepts_ar_placeholder'))
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('design_lab_description.en')
                    ->label(__('admin.products.fields.design_lab_en'))
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('design_lab_description.ar')
                    ->label(__('admin.products.fields.design_lab_ar'))
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('creative_lab_description.en')
                    ->label(__('admin.products.fields.creative_lab_en'))
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('creative_lab_description.ar')
                    ->label(__('admin.products.fields.creative_lab_ar'))
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\Repeater::make('curriculum_alignments')
                    ->label(__('admin.products.fields.curriculum_alignment'))
                    ->schema([
                        Forms\Components\TextInput::make('grade_level.en')
                            ->label(__('admin.products.fields.grade_level_en'))
                            ->maxLength(100),
                        Forms\Components\TextInput::make('grade_level.ar')
                            ->label(__('admin.products.fields.grade_level_ar'))
                            ->maxLength(100),
                        Forms\Components\TextInput::make('lesson_name.en')
                            ->label(__('admin.products.fields.lesson_name_en'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('lesson_name.ar')
                            ->label(__('admin.products.fields.lesson_name_ar'))
                            ->maxLength(255),
                    ])
                    ->defaultItems(0)
                    ->reorderable()
                    ->collapsible()
                    ->columnSpanFull(),
            ])->columns(2)->collapsed(),
            Forms\Components\Section::make(__('admin.products.sections.pricing'))->schema([
                Forms\Components\TextInput::make('price')
                    ->label(__('admin.common.fields.price'))
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->prefix('EGP'),
                Forms\Components\TextInput::make('compare_price')
                    ->label(__('admin.products.fields.compare_price'))
                    ->numeric()
                    ->minValue(0)
                    ->prefix('EGP')
                    ->helperText(__('admin.products.fields.compare_price_help')),
                Forms\Components\Select::make('currency')
                    ->label(__('admin.common.fields.currency'))
                    ->options(['EGP' => 'EGP', 'KWD' => 'KWD', 'USD' => 'USD'])
                    ->default('EGP')
                    ->required(),
                Forms\Components\TextInput::make('stock_quantity')
                    ->label(__('admin.products.fields.stock_quantity'))
                    ->numeric()
                    ->minValue(0),
                Forms\Components\Toggle::make('manage_stock')
                    ->label(__('admin.products.fields.manage_stock'))
                    ->live(),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.products.sections.content_ar'))->schema([
                Forms\Components\TextInput::make('name.ar')
                    ->label(__('admin.common.fields.name_ar'))
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
                    ->label(__('admin.common.fields.short_description_ar'))
                    ->rows(3),
                Forms\Components\Textarea::make('description.ar')
                    ->label(__('admin.common.fields.full_description_ar'))
                    ->rows(5),
            ]),
            Forms\Components\Section::make(__('admin.products.sections.content_en'))->schema([
                Forms\Components\TextInput::make('name.en')
                    ->label(__('admin.common.fields.name_en'))
                    ->maxLength(255),
                Forms\Components\Textarea::make('short_description.en')
                    ->label(__('admin.common.fields.short_description_en'))
                    ->rows(3),
                Forms\Components\Textarea::make('description.en')
                    ->label(__('admin.common.fields.full_description_en'))
                    ->rows(5),
            ]),
            Forms\Components\Section::make(__('admin.products.sections.seo'))->schema([
                Forms\Components\TextInput::make('meta_title.ar')
                    ->label(__('admin.common.fields.meta_title_ar'))
                    ->maxLength(255),
                Forms\Components\TextInput::make('meta_title.en')
                    ->label(__('admin.common.fields.meta_title_en'))
                    ->maxLength(255)
                    ->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\Textarea::make('meta_description.ar')
                    ->label(__('admin.common.fields.meta_description_ar'))
                    ->rows(2),
                Forms\Components\Textarea::make('meta_description.en')
                    ->label(__('admin.common.fields.meta_description_en'))
                    ->rows(2)
                    ->extraInputAttributes(['dir' => 'ltr']),
            ])->columns(2)->collapsed(),
            Forms\Components\Section::make(fn (): string => (string) __('sales_channels.product_section'))
                ->schema([
                    Forms\Components\ViewField::make('sales_channels_status')
                        ->label('')
                        ->view('filament.forms.components.product-sales-channels')
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ])
                ->visibleOn('edit')
                ->collapsed(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('product_image')
                    ->collection('image')
                    ->label(__('admin.common.fields.image'))
                    ->square(),
                Tables\Columns\TextColumn::make('sku')
                    ->label(__('admin.common.fields.sku'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.common.fields.name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('category.slug')
                    ->label(__('admin.products.table.category'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('admin.common.fields.type'))
                    ->badge(),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('admin.common.fields.price'))
                    ->money('EGP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.common.fields.status'))
                    ->badge(),
                Tables\Columns\TextColumn::make('course.slug')
                    ->label(__('admin.products.table.course'))
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_featured')
                    ->label(__('admin.products.table.featured'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('published_at')
                    ->label(__('admin.products.fields.published_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('admin.common.fields.updated_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('admin.common.fields.type'))
                    ->options(collect(ProductType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.common.fields.status'))
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
