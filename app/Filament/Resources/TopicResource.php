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

    protected static ?string $navigationGroup = 'Learning';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Topic details')->schema([
                Forms\Components\Select::make('lesson_id')
                    ->relationship('lesson', 'slug')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->default(fn () => request()->query('lesson_id')),

                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
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
                    ->numeric()
                    ->minValue(0)
                    ->default(fn (Forms\Get $get): int => (int) Topic::query()
                        ->where('lesson_id', $get('lesson_id'))
                        ->max('sort_order') + 1),

                Forms\Components\Select::make('content_type')
                    ->options([
                        'video' => 'Video',
                        'text' => 'Text',
                        'pdf' => 'PDF',
                        'interactive' => 'Interactive',
                    ])
                    ->required()
                    ->default('video')
                    ->live(),

                Forms\Components\Toggle::make('is_published')
                    ->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Title & content')->schema([
                Forms\Components\TextInput::make('title.ar')
                    ->label('Title (AR)')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Forms\Set $set, ?string $state, Forms\Get $get): void {
                        if (filled($get('slug'))) {
                            return;
                        }
                        $set('slug', Str::slug($state ?? ''));
                    }),
                Forms\Components\TextInput::make('title.en')
                    ->label('Title (EN)'),

                Forms\Components\Textarea::make('content.ar')
                    ->label('Content (AR)')
                    ->rows(4)
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get): bool => $get('content_type') === 'text'),
                Forms\Components\Textarea::make('content.en')
                    ->label('Content (EN)')
                    ->rows(4)
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get): bool => $get('content_type') === 'text'),
            ])->columns(2),

            Forms\Components\Section::make('Video')
                ->visible(fn (Forms\Get $get): bool => $get('content_type') === 'video')
                ->schema([
                    BunnyVideoUpload::make('bunny_video_id')
                        ->label('Video file')
                        ->dehydrated(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('lesson'))
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('title')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('lesson.slug')->label('Lesson')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('content_type')->badge(),
                Tables\Columns\TextColumn::make('video_status')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'finished' => 'success',
                        'processing' => 'warning',
                        'error' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_published')->boolean(),
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