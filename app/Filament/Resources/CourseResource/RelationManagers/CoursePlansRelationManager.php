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

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return (string) __('admin.course_plans.title');
    }

    /** @var array<string, list<int>>|null */
    private ?array $pendingSelection = null;

    public function form(Form $form): Form
    {
        $course = $this->getOwnerRecord();

        return $form->schema([
            Forms\Components\Section::make(__('admin.course_plans.sections.basic'))->schema([
                Forms\Components\TextInput::make('name.ar')->label(__('admin.course_plans.fields.name_ar'))->required(),
                Forms\Components\TextInput::make('name.en')->label(__('admin.course_plans.fields.name_en')),
                Forms\Components\Textarea::make('description.ar')->label(__('admin.course_plans.fields.description_ar'))->rows(2),
                Forms\Components\Textarea::make('description.en')->label(__('admin.course_plans.fields.description_en'))->rows(2),
                Forms\Components\Toggle::make('is_active')->label(__('admin.common.fields.active'))->default(true),
                Forms\Components\TextInput::make('sort_order')->label(__('admin.common.fields.order'))->numeric()->default(0),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.course_plans.sections.pricing'))->schema([
                Forms\Components\TextInput::make('price')->label(__('admin.common.fields.price'))->numeric()->minValue(0)->default(0)->required(),
                Forms\Components\TextInput::make('currency')->label(__('admin.common.fields.currency'))->default('EGP')->maxLength(3)->required()->extraInputAttributes(['dir' => 'ltr']),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.course_plans.sections.access_duration'))->schema([
                Forms\Components\Toggle::make('is_lifetime')->label(__('admin.course_plans.fields.lifetime'))->default(true)->live(),
                Forms\Components\TextInput::make('duration_days')
                    ->numeric()
                    ->minValue(1)
                    ->label(__('admin.course_plans.fields.duration_days'))
                    ->visible(fn (Forms\Get $get): bool => ! $get('is_lifetime')),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.course_plans.sections.quiz_settings'))->schema([
                Forms\Components\TextInput::make('max_quiz_attempts')
                    ->numeric()
                    ->minValue(1)
                    ->label(__('admin.course_plans.fields.max_quiz_attempts'))
                    ->helperText(__('admin.course_plans.fields.max_quiz_attempts_help')),
                Forms\Components\Toggle::make('grant_certificate')->label(__('admin.course_plans.fields.grant_certificate')),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.course_plans.sections.lessons'))->schema([
                Forms\Components\CheckboxList::make('lesson_ids')
                    ->label(__('admin.course_plans.fields.accessible_lessons'))
                    ->options(fn (): array => $course->lessons()->orderBy('sort_order')->get()
                        ->mapWithKeys(fn (Lesson $lesson): array => [
                            $lesson->id => ($lesson->getTranslation('title', 'en') ?: $lesson->slug).' ('.$lesson->slug.')',
                        ])->all())
                    ->columns(2)
                    ->bulkToggleable()
                    ->selectAllAction(
                        fn (Action $action): Action => $action->label(__('admin.course_plans.actions.select_all_lessons')),
                    )
                    ->deselectAllAction(
                        fn (Action $action): Action => $action->label(__('admin.course_plans.actions.deselect_all_lessons')),
                    )
                    ->dehydrated(true),
            ]),
            Forms\Components\Section::make(__('admin.course_plans.sections.topics'))->schema([
                Forms\Components\CheckboxList::make('topic_ids')
                    ->label(__('admin.course_plans.fields.accessible_topics'))
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
                        fn (Action $action): Action => $action->label(__('admin.course_plans.actions.select_all_topics')),
                    )
                    ->deselectAllAction(
                        fn (Action $action): Action => $action->label(__('admin.course_plans.actions.deselect_all_topics')),
                    )
                    ->dehydrated(true),
            ]),
            Forms\Components\Section::make(__('admin.course_plans.sections.quizzes'))->schema([
                Forms\Components\CheckboxList::make('quiz_ids')
                    ->label(__('admin.course_plans.fields.accessible_quizzes'))
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
                        fn (Action $action): Action => $action->label(__('admin.course_plans.actions.select_all_quizzes')),
                    )
                    ->deselectAllAction(
                        fn (Action $action): Action => $action->label(__('admin.course_plans.actions.deselect_all_quizzes')),
                    )
                    ->dehydrated(true),
            ]),
            Forms\Components\Section::make(__('admin.course_plans.sections.interactive_activities'))->schema([
                Forms\Components\CheckboxList::make('interactive_activity_ids')
                    ->label(__('admin.course_plans.fields.accessible_interactive_activities'))
                    ->options(fn (): array => InteractiveActivity::query()
                        ->whereIn('lesson_id', $course->lessons()->pluck('id'))
                        ->get()
                        ->mapWithKeys(fn (InteractiveActivity $activity): array => [
                            $activity->id => ($activity->getTranslation('title', 'en') ?: 'Activity').' #'.$activity->id,
                        ])->all())
                    ->columns(2)
                    ->bulkToggleable()
                    ->selectAllAction(
                        fn (Action $action): Action => $action->label(__('admin.course_plans.actions.select_all_interactive_activities')),
                    )
                    ->deselectAllAction(
                        fn (Action $action): Action => $action->label(__('admin.course_plans.actions.deselect_all_interactive_activities')),
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
                Tables\Columns\TextColumn::make('name')
                    ->label(__('admin.common.fields.name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('price')
                    ->label(__('admin.common.fields.price'))
                    ->money(fn (CoursePlan $record): string => $record->currency),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('admin.common.fields.active'))
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_lifetime')
                    ->label(__('admin.course_plans.fields.lifetime'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('duration_days')->label(__('admin.course_plans.table.days')),
                Tables\Columns\TextColumn::make('max_quiz_attempts')->label(__('admin.course_plans.table.max_attempts')),
                Tables\Columns\TextColumn::make('entitlements_count')->counts('entitlements')->label(__('admin.course_plans.table.rules')),
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
