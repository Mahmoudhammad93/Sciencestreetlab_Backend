<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\RelationManagers;

use App\Filament\Resources\QuizResource;
use App\Modules\Learning\Domain\Enums\LessonType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class LessonsRelationManager extends RelationManager
{
    protected static string $relationship = 'lessons';

    protected static ?string $title = 'Lessons';

    protected static ?string $recordTitleAttribute = 'slug';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Lesson details')->schema([
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: Lesson::class,
                        column: 'slug',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                            'course_id',
                            $this->getOwnerRecord()->getKey(),
                        ),
                    )
                    ->helperText('URL-safe identifier, unique within this course.'),
                Forms\Components\Select::make('lesson_type')
                    ->options(collect(LessonType::cases())->mapWithKeys(
                        fn (LessonType $type): array => [$type->value => Str::headline($type->name)],
                    ))
                    ->required()
                    ->default(LessonType::Theory->value),
                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->minValue(1)
                    ->default(fn (): int => (int) $this->getOwnerRecord()->lessons()->max('sort_order') + 1)
                    ->helperText('Display order inside the course.'),
                Forms\Components\Toggle::make('is_published')
                    ->default(true),
                Forms\Components\TextInput::make('video_duration_seconds')
                    ->numeric()
                    ->minValue(0)
                    ->label('Video duration (seconds)'),
            ])->columns(2),
            Forms\Components\Section::make('Content')->schema([
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
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('content.en')
                    ->label('Content (EN)')
                    ->rows(4)
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['topics', 'course']))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('lesson_type')
                    ->badge(),
                Tables\Columns\TextColumn::make('topics_count')
                    ->counts('topics')
                    ->label('Topics'),
                Tables\Columns\TextColumn::make('quizzes_count')
                    ->counts('quizzes')
                    ->label('Quizzes'),
                Tables\Columns\IconColumn::make('is_published')
                    ->boolean()
                    ->label('Published'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('quiz')
                    ->label(fn (Lesson $record): string => $record->quizzes()->exists() ? 'Edit quiz' : 'Assign quiz')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->url(function (Lesson $record): string {
                        $quiz = $record->quizzes()->first();
                        if ($quiz !== null) {
                            return QuizResource::getUrl('edit', ['record' => $quiz]);
                        }

                        return QuizResource::getUrl('create').'?lesson_id='.$record->id;
                    }),
                Tables\Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Lesson $record): string => self::lessonPreviewUrl($record))
                    ->openUrlInNewTab(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Lesson')
                ->columns(2)
                ->schema([
                    TextEntry::make('title')
                        ->label('Title'),
                    TextEntry::make('slug'),
                    TextEntry::make('lesson_type')
                        ->badge(),
                    TextEntry::make('sort_order')
                        ->label('Order'),
                    IconEntry::make('is_published')
                        ->boolean()
                        ->label('Published'),
                    TextEntry::make('video_duration_seconds')
                        ->label('Video duration (sec)')
                        ->placeholder('—'),
                    TextEntry::make('content')
                        ->label('Content')
                        ->columnSpanFull()
                        ->markdown()
                        ->placeholder('—'),
                ]),
            Section::make('Topics')
                ->schema([
                    RepeatableEntry::make('topics')
                        ->label('')
                        ->schema([
                            TextEntry::make('sort_order')
                                ->label('#'),
                            TextEntry::make('title')
                                ->label('Title'),
                            TextEntry::make('slug'),
                            TextEntry::make('content_type')
                                ->badge(),
                            TextEntry::make('video_url')
                                ->label('Video URL')
                                ->placeholder('—')
                                ->url(fn ($state) => filled($state) ? $state : null)
                                ->openUrlInNewTab(),
                            IconEntry::make('is_published')
                                ->boolean()
                                ->label('Published'),
                        ])
                        ->columns(3)
                        ->columnSpanFull(),
                ])
                ->visible(fn (Lesson $record): bool => $record->topics()->exists()),
        ]);
    }

    public static function lessonPreviewUrl(Lesson $lesson): string
    {
        $course = $lesson->relationLoaded('course')
            ? $lesson->course
            : $lesson->course()->first();

        $slug = $course instanceof Course ? $course->slug : 'course';

        return self::frontendUrl('/courses/'.$slug.'/'.$lesson->id);
    }

    public static function coursePreviewUrl(Course $course): string
    {
        return self::frontendUrl('/courses/'.$course->slug);
    }

    private static function frontendUrl(string $path): string
    {
        return rtrim((string) config('sciencestreet.frontend_url', 'http://localhost:5173'), '/').$path;
    }
}
