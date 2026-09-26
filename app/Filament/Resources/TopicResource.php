<?php
// app/Filament/Resources/TopicResource.php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Forms\Components\BunnyVideoUpload;
use App\Filament\Resources\TopicResource\Pages;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class TopicResource extends Resource
{
    protected static ?string $model = Topic::class;

    protected static ?string $navigationIcon = 'heroicon-o-play-circle';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.learning');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.topics');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.topics');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.topics');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.topics.sections.details'))->schema([
                Forms\Components\Select::make('lesson_id')
                    ->label(__('admin.common.fields.lesson'))
                    ->relationship('lesson', 'slug')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->default(fn () => request()->query('lesson_id')),

                Forms\Components\TextInput::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->required()
                    ->maxLength(255)
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->unique(
                        table: Topic::class,
                        column: 'slug',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Forms\Get $get, Unique $rule): Unique => $rule->where(
                            'lesson_id',
                            $get('lesson_id'),
                        ),
                    ),

                Forms\Components\TextInput::make('sort_order')
                    ->label(__('admin.common.fields.order'))
                    ->numeric()
                    ->minValue(0)
                    ->default(fn (Forms\Get $get): int => (int) Topic::query()
                        ->where('lesson_id', $get('lesson_id'))
                        ->max('sort_order') + 1),

                Forms\Components\Select::make('content_type')
                    ->label(__('admin.common.fields.type'))
                    ->options([
                        'video' => __('admin.topics.content_type.video'),
                        'text' => __('admin.topics.content_type.text'),
                        'pdf' => __('admin.topics.content_type.pdf'),
                        'interactive' => __('admin.topics.content_type.interactive'),
                    ])
                    ->required()
                    ->default('video')
                    ->live(),

                Forms\Components\Toggle::make('is_published')
                    ->label(__('admin.common.status.published'))
                    ->default(true),
            ])->columns(2),

            Forms\Components\Section::make(__('admin.topics.sections.title_content'))->schema([
                Forms\Components\TextInput::make('title.ar')
                    ->label(__('admin.topics.fields.title_ar'))
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Forms\Set $set, ?string $state, Forms\Get $get): void {
                        if (filled($get('slug'))) {
                            return;
                        }
                        $set('slug', Str::slug($state ?? ''));
                    }),
                Forms\Components\TextInput::make('title.en')
                    ->label(__('admin.topics.fields.title_en')),

                Forms\Components\Textarea::make('content.ar')
                    ->label(__('admin.topics.fields.content_ar'))
                    ->rows(4)
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get): bool => $get('content_type') === 'text'),
                Forms\Components\Textarea::make('content.en')
                    ->label(__('admin.topics.fields.content_en'))
                    ->rows(4)
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get): bool => $get('content_type') === 'text'),
            ])->columns(2),

            Forms\Components\Section::make(__('admin.topics.sections.video'))
                ->visible(fn (Forms\Get $get): bool => $get('content_type') === 'video')
                ->schema([
                    BunnyVideoUpload::make('bunny_video_id')
                        ->label(__('admin.topics.fields.video_file'))
                        ->dehydrated(true),
                ]),

            Forms\Components\Section::make(__('admin.topics.sections.interactive_html'))
                ->description(__('admin.topics.sections.interactive_description'))
                ->visible(fn (Forms\Get $get): bool => $get('content_type') === 'interactive')
                ->schema([
                    Forms\Components\FileUpload::make('interactive_html_upload')
                        ->label(__('admin.topics.fields.html_file'))
                        ->acceptedFileTypes([
                            'text/html',
                            'application/xhtml+xml',
                            'application/octet-stream',
                        ])
                        ->maxSize(65536)
                        ->previewable(false)
                        ->disk('local')
                        ->visibility('private')
                        ->directory('tmp/topic-interactive-uploads')
                        ->dehydrated(false)
                        ->helperText(__('admin.topics.fields.interactive_html_help')),
                    Forms\Components\FileUpload::make('interactive_zip_upload')
                        ->label(__('admin.topics.fields.zip_package'))
                        ->acceptedFileTypes([
                            'application/zip',
                            'application/x-zip-compressed',
                            'application/octet-stream',
                        ])
                        ->maxSize(65536)
                        ->previewable(false)
                        ->disk('local')
                        ->visibility('private')
                        ->directory('tmp/topic-interactive-uploads')
                        ->dehydrated(false)
                        ->helperText(__('admin.topics.fields.interactive_html_help')),
                    Forms\Components\Placeholder::make('interactive_activity_info')
                        ->label(__('admin.topics.fields.linked_activity'))
                        ->content(function (?Topic $record): string {
                            if (! $record) {
                                return __('admin.topics.placeholders.linked_activity_after_save');
                            }
                            $activity = $record->interactiveActivity;
                            if (! $activity) {
                                return __('admin.topics.placeholders.linked_activity_no_package');
                            }

                            $hasPackage = filled($activity->activity_package_path);

                            return '#'.$activity->id
                                .($hasPackage ? ' — '.__('admin.topics.placeholders.linked_activity_ready') : '');
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('lesson'))
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label(__('admin.topics.table.sort_order'))->sortable(),
                Tables\Columns\TextColumn::make('title')->label(__('admin.common.fields.title'))->searchable()->wrap(),
                Tables\Columns\TextColumn::make('lesson.slug')->label(__('admin.topics.table.lesson'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('content_type')->label(__('admin.common.fields.type'))->badge(),
                Tables\Columns\TextColumn::make('video_status')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'finished' => 'success',
                        'processing' => 'warning',
                        'error' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_published')->label(__('admin.common.status.published'))->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('lesson_id')
                    ->relationship('lesson', 'slug')
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTopics::route('/'),
            'create' => Pages\CreateTopic::route('/create'),
            'edit' => Pages\EditTopic::route('/{record}/edit'),
        ];
    }
}