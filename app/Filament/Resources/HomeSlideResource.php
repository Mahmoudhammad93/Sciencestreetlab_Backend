<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Forms\Components\ImageDropzone;
use App\Filament\Resources\HomeSlideResource\Pages;
use App\Modules\Content\Infrastructure\Persistence\Models\HomeSlide;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class HomeSlideResource extends Resource
{
    protected static ?string $model = HomeSlide::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.home_slides');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.home_slides');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.home_slides');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.home_slides.sections.slide'))->schema([
                ImageDropzone::make(
                    'image',
                    'home-slides',
                    __('admin.home_slides.fields.image'),
                    __('admin.home_slides.fields.image_help')
                )->required(fn (string $operation): bool => $operation === 'create'),
                Forms\Components\ColorPicker::make('background_color')
                    ->label(__('admin.home_slides.fields.background_color'))
                    ->hex()
                    ->default('#4B208C')
                    ->required()
                    ->rules([
                        'required',
                        'string',
                        'max:20',
                        'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{4}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/',
                    ])
                    ->validationMessages([
                        'regex' => __('admin.home_slides.validation.background_color'),
                    ]),
                Forms\Components\TextInput::make('link')
                    ->label(__('admin.home_slides.fields.link'))
                    ->helperText(__('admin.home_slides.fields.link_help'))
                    ->maxLength(2048)
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->nullable()
                    ->rules([
                        'nullable',
                        'string',
                        'max:2048',
                        fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (! HomeSlide::isSafeLink(is_string($value) ? $value : null)) {
                                $fail(__('admin.home_slides.validation.link'));
                            }
                        },
                    ]),
                Forms\Components\Radio::make('link_target')
                    ->label(__('admin.home_slides.fields.link_target'))
                    ->options([
                        '_self' => __('admin.home_slides.link_targets.self'),
                        '_blank' => __('admin.home_slides.link_targets.blank'),
                    ])
                    ->default('_self')
                    ->required()
                    ->inline()
                    ->rules([Rule::in(['_self', '_blank'])]),
                Forms\Components\Toggle::make('is_active')
                    ->label(__('admin.common.fields.active'))
                    ->default(true),
                Forms\Components\TextInput::make('sort_order')
                    ->label(__('admin.home_slides.fields.sort_order'))
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Forms\Components\Fieldset::make(__('admin.home_slides.fields.duration'))
                    ->schema([
                        Forms\Components\Select::make('duration_minutes')
                            ->label(__('admin.home_slides.fields.minutes'))
                            ->options(self::durationUnitOptions())
                            ->default(0)
                            ->required()
                            ->native(false)
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Forms\Components\Select $component, mixed $state, ?HomeSlide $record): void {
                                $total = (int) ($record?->display_duration_seconds ?? 6);
                                $component->state(intdiv(max(1, $total), 60));
                            }),
                        Forms\Components\Select::make('duration_seconds')
                            ->label(__('admin.home_slides.fields.seconds'))
                            ->helperText(__('admin.home_slides.fields.duration_help'))
                            ->options(self::durationUnitOptions())
                            ->default(6)
                            ->required()
                            ->native(false)
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Forms\Components\Select $component, mixed $state, ?HomeSlide $record): void {
                                $total = (int) ($record?->display_duration_seconds ?? 6);
                                $component->state(max(1, $total) % 60);
                            })
                            ->rules([
                                fn (Forms\Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    $minutes = max(0, (int) $get('duration_minutes'));
                                    $seconds = max(0, (int) $value);
                                    if (($minutes * 60) + $seconds < 1) {
                                        $fail(__('admin.home_slides.validation.duration'));
                                    }
                                },
                            ]),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function durationUnitOptions(): array
    {
        $options = [];

        for ($i = 0; $i <= 59; $i++) {
            $options[$i] = sprintf('%02d', $i);
        }

        return $options;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label(__('admin.home_slides.table.image'))
                    ->getStateUsing(fn (HomeSlide $record): ?string => ImageDropzone::publicUrl($record->image)),
                Tables\Columns\ColorColumn::make('background_color')
                    ->label(__('admin.home_slides.table.background_color')),
                Tables\Columns\TextColumn::make('link')
                    ->label(__('admin.home_slides.table.link'))
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('link_target')
                    ->label(__('admin.home_slides.table.link_target'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === '_blank'
                        ? __('admin.home_slides.link_targets.blank')
                        : __('admin.home_slides.link_targets.self')),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('admin.common.fields.active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('admin.home_slides.table.sort_order'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('display_duration_seconds')
                    ->label(__('admin.home_slides.table.duration'))
                    ->formatStateUsing(function (int $state): string {
                        $minutes = intdiv(max(1, $state), 60);
                        $seconds = max(1, $state) % 60;

                        return sprintf('%d:%02d', $minutes, $seconds);
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.common.fields.created_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('admin.common.fields.updated_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('admin.common.fields.active')),
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
            'index' => Pages\ListHomeSlides::route('/'),
            'create' => Pages\CreateHomeSlide::route('/create'),
            'edit' => Pages\EditHomeSlide::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rawFormState
     * @return array<string, mixed>
     */
    public static function withDisplayDuration(array $data, array $rawFormState): array
    {
        $minutes = max(0, min(59, (int) ($rawFormState['duration_minutes'] ?? 0)));
        $seconds = max(0, min(59, (int) ($rawFormState['duration_seconds'] ?? 0)));
        $data['display_duration_seconds'] = max(1, ($minutes * 60) + $seconds);

        return $data;
    }
}
