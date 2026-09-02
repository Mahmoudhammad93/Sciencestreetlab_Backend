<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\RelationManagers;

use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Quiz;
use App\Modules\Learning\Application\Services\CoursePlanEntitlementSyncService;
use App\Modules\Learning\Infrastructure\Persistence\Models\CoursePlan;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use App\Modules\Learning\Infrastructure\Persistence\Models\Topic;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CoursePlansRelationManager extends RelationManager
{
    protected static string $relationship = 'plans';

    protected static ?string $title = 'Course Plans';

    /** @var array<string, list<int>>|null */
    private ?array $pendingSelection = null;

    public function form(Form $form): Form
    {
        $course = $this->getOwnerRecord();

        return $form->schema([
            Forms\Components\Section::make('Basic information')->schema([
                Forms\Components\TextInput::make('name.ar')->label('Name (AR)')->required(),
                Forms\Components\TextInput::make('name.en')->label('Name (EN)'),
                Forms\Components\Textarea::make('description.ar')->label('Description (AR)')->rows(2),
                Forms\Components\Textarea::make('description.en')->label('Description (EN)')->rows(2),
                Forms\Components\Toggle::make('is_active')->default(true),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
            ])->columns(2),
            Forms\Components\Section::make('Pricing')->schema([
                Forms\Components\TextInput::make('price')->numeric()->minValue(0)->default(0)->required(),
                Forms\Components\TextInput::make('currency')->default('EGP')->maxLength(3)->required(),
            ])->columns(2),
            Forms\Components\Section::make('Access duration')->schema([
                Forms\Components\Toggle::make('is_lifetime')->label('Lifetime access')->default(true)->live(),
                Forms\Components\TextInput::make('duration_days')
                    ->numeric()
                    ->minValue(1)
                    ->label('Duration (days)')
                    ->visible(fn (Forms\Get $get): bool => ! $get('is_lifetime')),
            ])->columns(2),
            Forms\Components\Section::make('Quiz settings')->schema([
                Forms\Components\TextInput::make('max_quiz_attempts')
                    ->numeric()
                    ->minValue(1)
                    ->label('Maximum quiz attempts')
                    ->helperText('Leave empty for unlimited attempts (quiz-level limits may still apply).'),
                Forms\Components\Toggle::make('grant_certificate')->label('Certificate access'),
            ])->columns(2),
            Forms\Components\Section::make('Lessons')->schema([
                Forms\Components\CheckboxList::make('lesson_ids')
                    ->label('Accessible lessons')
                    ->options(fn (): array => $course->lessons()->orderBy('sort_order')->get()
                        ->mapWithKeys(fn (Lesson $lesson): array => [
                            $lesson->id => ($lesson->getTranslation('title', 'en') ?: $lesson->slug).' ('.$lesson->slug.')',
                        ])->all())
                    ->columns(2)
                    ->bulkToggleable()
                    ->selectAllAction(
                        fn (Action $action): Action => $action->label('Select all lessons'),
                    )
                    ->deselectAllAction(
                        fn (Action $action): Action => $action->label('Deselect all lessons'),
                    )
                    ->dehydrated(true),
            ]),
            Forms\Components\Section::make('Topics')->schema([
                Forms\Components\CheckboxList::make('topic_ids')
                    ->label('Accessible topics')
                    ->options(fn (): array => Topic::query()
                        ->whereIn('lesson_id', $course->lessons()->pluck('id'))
                        ->orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn (Topic $topic): array => [
                            $topic->id => ($topic->getTranslation('title', 'en') ?: $topic->slug).' ('.$topic->slug.')',
                        ])->all())
                    ->columns(2)
                    ->bulkToggleable()
                    ->selectAllAction(
                        fn (Action $action): Action => $action->label('Select all topics'),
                    )
                    ->deselectAllAction(
                        fn (Action $action): Action => $action->label('Deselect all topics'),
                    )
                    ->dehydrated(true),
            ]),
            Forms\Components\Section::make('Quizzes')->schema([
                Forms\Components\CheckboxList::make('quiz_ids')
                    ->label('Accessible quizzes')
                    ->options(fn (): array => Quiz::query()
                        ->where('quizable_type', Lesson::class)
                        ->whereIn('quizable_id', $course->lessons()->pluck('id'))
                        ->get()
                        ->mapWithKeys(fn (Quiz $quiz): array => [
                            $quiz->id => ($quiz->getTranslation('title', 'en') ?: 'Quiz').' #'.$quiz->id,
                        ])->all())
                    ->columns(2)
                    ->bulkToggleable()
                    ->selectAllAction(
                        fn (Action $action): Action => $action->label('Select all quizzes'),
                    )
                    ->deselectAllAction(
                        fn (Action $action): Action => $action->label('Deselect all quizzes'),
                    )
                    ->dehydrated(true),
            ]),
            Forms\Components\Section::make('Interactive activities')->schema([
                Forms\Components\CheckboxList::make('interactive_activity_ids')
                    ->label('Accessible interactive activities')
                    ->options(fn (): array => InteractiveActivity::query()
                        ->whereIn('lesson_id', $course->lessons()->pluck('id'))
                        ->get()
                        ->mapWithKeys(fn (InteractiveActivity $activity): array => [
                            $activity->id => ($activity->getTranslation('title', 'en') ?: 'Activity').' #'.$activity->id,
                        ])->all())
                    ->columns(2)
                    ->bulkToggleable()
                    ->selectAllAction(
                        fn (Action $action): Action => $action->label('Select all interactive activities'),
                    )
                    ->deselectAllAction(
                        fn (Action $action): Action => $action->label('Deselect all interactive activities'),
                    )
                    ->dehydrated(true),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('price')->money(fn (CoursePlan $record): string => $record->currency),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\IconColumn::make('is_lifetime')->boolean(),
                Tables\Columns\TextColumn::make('duration_days')->label('Days'),
                Tables\Columns\TextColumn::make('max_quiz_attempts')->label('Max attempts'),
                Tables\Columns\TextColumn::make('entitlements_count')->counts('entitlements')->label('Rules'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $this->pendingSelection = $this->extractSelection($data);

                        return collect($data)->except(['lesson_ids', 'topic_ids', 'quiz_ids', 'interactive_activity_ids'])->all();
                    })
                    ->after(function (CoursePlan $record): void {
                        if ($this->pendingSelection !== null) {
                            app(CoursePlanEntitlementSyncService::class)->syncPlanEntitlements($record, $this->pendingSelection);
                            $this->pendingSelection = null;
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, CoursePlan $record): array {
                        return array_merge($data, app(CoursePlanEntitlementSyncService::class)->selectionFromPlan($record));
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $this->pendingSelection = $this->extractSelection($data);

                        return collect($data)->except(['lesson_ids', 'topic_ids', 'quiz_ids', 'interactive_activity_ids'])->all();
                    })
                    ->after(function (CoursePlan $record): void {
                        if ($this->pendingSelection !== null) {
                            app(CoursePlanEntitlementSyncService::class)->syncPlanEntitlements($record, $this->pendingSelection);
                            $this->pendingSelection = null;
                        }
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *   lesson_ids: list<int>,
     *   topic_ids: list<int>,
     *   quiz_ids: list<int>,
     *   interactive_activity_ids: list<int>
     * }
     */
    private function extractSelection(array $data): array
    {
        return [
            'lesson_ids' => array_map('intval', $data['lesson_ids'] ?? []),
            'topic_ids' => array_map('intval', $data['topic_ids'] ?? []),
            'quiz_ids' => array_map('intval', $data['quiz_ids'] ?? []),
            'interactive_activity_ids' => array_map('intval', $data['interactive_activity_ids'] ?? []),
        ];
    }
}
