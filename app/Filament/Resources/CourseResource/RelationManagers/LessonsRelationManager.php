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

    protected static ?string $recordTitleAttribute = 'slug';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return (string) __('admin.courses.relations.lessons.title');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.lessons.sections.details'))->schema([
                Forms\Components\TextInput::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->required()
                    ->maxLength(255)
                    ->extraInputAttributes(['dir' => 'ltr'])
                    ->unique(
                        table: Lesson::class,
                        column: 'slug',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                            'course_id',
                            $this->getOwnerRecord()->getKey(),
                        ),
                    )
                    ->helperText(__('admin.lessons.fields.slug_help')),
                Forms\Components\Select::make('lesson_type')
                    ->label(__('admin.common.fields.type'))
                    ->options(collect(LessonType::cases())->mapWithKeys(
                        fn (LessonType $type): array => [$type->value => Str::headline($type->name)],
                    ))
                    ->required()
                    ->default(LessonType::Theory->value),
                Forms\Components\TextInput::make('sort_order')
                    ->label(__('admin.common.fields.order'))
                    ->numeric()
                    ->minValue(1)
                    ->default(fn (): int => (int) $this->getOwnerRecord()->lessons()->max('sort_order') + 1)
                    ->helperText(__('admin.lessons.fields.sort_order_help')),
                Forms\Components\Toggle::make('is_published')
                    ->label(__('admin.lessons.table.published'))
                    ->default(true),
                Forms\Components\TextInput::make('video_duration_seconds')
                    ->numeric()
                    ->minValue(0)
                    ->label(__('admin.lessons.fields.video_duration')),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.lessons.sections.content'))->schema([
                Forms\Components\TextInput::make('title.ar')
                    ->label(__('admin.lessons.fields.title_ar'))
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Forms\Set $set, ?string $state, Forms\Get $get): void {
                        if (filled($get('slug'))) {
                            return;
                        }

                        $set('slug', Str::slug($state ?? ''));
                    }),
                Forms\Components\TextInput::make('title.en')
                    ->label(__('admin.lessons.fields.title_en')),
                Forms\Components\Textarea::make('content.ar')
                    ->label(__('admin.lessons.fields.content_ar'))
                    ->rows(4)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('content.en')
                    ->label(__('admin.lessons.fields.content_en'))
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
                    ->label(__('admin.lessons.table.sort_order'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('admin.lessons.table.title'))
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->searchable()
                    ->copyable()
                    ->extraAttributes(['dir' => 'ltr']),
                Tables\Columns\TextColumn::make('lesson_type')
                    ->label(__('admin.common.fields.type'))
                    ->badge(),
                Tables\Columns\TextColumn::make('topics_count')
                    ->counts('topics')
                    ->label(__('admin.lessons.table.topics')),
                Tables\Columns\TextColumn::make('quizzes_count')
                    ->counts('quizzes')
                    ->label(__('admin.lessons.table.quizzes')),
                Tables\Columns\IconColumn::make('is_published')
                    ->boolean()
                    ->label(__('admin.lessons.table.published')),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('add_quiz')
                    ->label(__('admin.lessons.actions.add_quiz'))
                    ->icon('heroicon-o-plus-circle')
                    ->url(fn (Lesson $record): string => QuizResource::getUrl('create').'?lesson_id='.$record->id),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('view_quizzes')
                        ->label(__('admin.lessons.actions.view_quizzes'))
                        ->icon('heroicon-o-clipboard-document-list')
                        ->url(fn (Lesson $record): string => QuizResource::getUrl('index').'?tableSearch='.urlencode((string) $record->slug))
                        ->visible(fn (Lesson $record): bool => $record->quizzes()->exists()),
                    Tables\Actions\Action::make('edit_first_quiz')
                        ->label(__('admin.lessons.actions.edit_quiz'))
                        ->icon('heroicon-o-pencil-square')
                        ->url(function (Lesson $record): string {
                            $quiz = $record->quizzes()->orderBy('id')->first();

                            return $quiz !== null
                                ? QuizResource::getUrl('edit', ['record' => $quiz])
                                : QuizResource::getUrl('index');
                        })
                        ->visible(fn (Lesson $record): bool => $record->quizzes()->count() === 1),
                ])
                    ->label(__('admin.lessons.actions.manage_quizzes'))
                    ->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn (Lesson $record): bool => $record->quizzes()->exists())
                    ->button(),
                Tables\Actions\Action::make('preview')
                    ->label(__('admin.common.actions.preview'))
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
            Section::make(__('admin.lessons.infolist.sections.lesson'))
                ->columns(2)
                ->schema([
                    TextEntry::make('title')
                        ->label(__('admin.lessons.infolist.fields.title')),
                    TextEntry::make('slug')
                        ->label(__('admin.common.fields.slug')),
                    TextEntry::make('lesson_type')
                        ->label(__('admin.common.fields.type'))
                        ->badge(),
                    TextEntry::make('sort_order')
                        ->label(__('admin.lessons.infolist.fields.order')),
                    IconEntry::make('is_published')
                        ->boolean()
                        ->label(__('admin.lessons.infolist.fields.published')),
                    TextEntry::make('video_duration_seconds')
                        ->label(__('admin.lessons.infolist.fields.video_duration'))
                        ->placeholder('—'),
                    TextEntry::make('content')
                        ->label(__('admin.lessons.infolist.fields.content'))
                        ->columnSpanFull()
                        ->markdown()
                        ->placeholder('—'),
                ]),
            Section::make(__('admin.lessons.infolist.sections.topics'))
                ->schema([
                    RepeatableEntry::make('topics')
                        ->label('')
                        ->schema([
                            TextEntry::make('sort_order')
                                ->label(__('admin.topics.table.sort_order')),
                            TextEntry::make('title')
                                ->label(__('admin.lessons.infolist.fields.title')),
                            TextEntry::make('slug')
                                ->label(__('admin.common.fields.slug')),
                            TextEntry::make('content_type')
                                ->label(__('admin.common.fields.type'))
                                ->badge(),
                            TextEntry::make('video_url')
                                ->label(__('admin.lessons.infolist.fields.video_url'))
                                ->placeholder('—')
                                ->url(fn ($state) => filled($state) ? $state : null)
                                ->openUrlInNewTab(),
                            IconEntry::make('is_published')
                                ->boolean()
                                ->label(__('admin.lessons.infolist.fields.published')),
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
