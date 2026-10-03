<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuizAttemptResource\Pages;

use App\Filament\Resources\QuizAttemptResource;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

final class ViewQuizAttempt extends ViewRecord
{
    protected static string $resource = QuizAttemptResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        /** @var \App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttempt $record */
        $record = $this->record;
        $record->loadMissing(['answers.question', 'user', 'quiz']);

        $answerEntries = [];
        foreach ($record->answers as $answer) {
            /** @var QuizAttemptAnswer $answer */
            $stem = $answer->question
                ? (string) $answer->question->getTranslation('body', app()->getLocale())
                : str_replace('…', (string) $answer->question_id, __('admin.quiz_attempts.grade_form.question_fallback'));

            $answerEntries[] = Infolists\Components\Section::make($stem)
                ->schema([
                    Infolists\Components\TextEntry::make('text_'.$answer->id)
                        ->label(__('admin.quiz_attempts.infolist.fields.student_answer'))
                        ->state((string) ($answer->text_answer ?: '—'))
                        ->visible($answer->question?->question_type?->value !== 'image_upload'),
                    Infolists\Components\TextEntry::make('images_'.$answer->id)
                        ->label(__('admin.quiz_attempts.grade_form.uploaded_images'))
                        ->html()
                        ->state(function () use ($answer, $record): string {
                            if ($answer->question?->question_type?->value !== 'image_upload') {
                                return '—';
                            }
                            $answer->loadMissing('media');
                            $html = $answer->getMedia(\App\Modules\Assessment\Infrastructure\Persistence\Models\QuizAttemptAnswer::MEDIA_COLLECTION)
                                ->map(function ($media) use ($record, $answer): string {
                                    $url = route('admin.quiz-attempt-images.show', [
                                        'attempt' => $record->id,
                                        'question' => $answer->question_id,
                                        'media' => $media->id,
                                    ]);

                                    return '<a href="'.e($url).'" target="_blank" rel="noopener"><img src="'.e($url).'" style="max-width:280px;max-height:200px;object-fit:contain" /></a>';
                                })->implode('<br>');

                            return $html !== '' ? $html : '—';
                        })
                        ->visible($answer->question?->question_type?->value === 'image_upload'),
                    Infolists\Components\TextEntry::make('pending_'.$answer->id)
                        ->label(__('admin.quiz_attempts.infolist.fields.needs_review'))
                        ->state($answer->needs_manual_review
                            ? __('admin.quiz_attempts.infolist.fields.needs_review_yes')
                            : __('admin.quiz_attempts.infolist.fields.needs_review_no')),
                    Infolists\Components\TextEntry::make('points_'.$answer->id)
                        ->label(__('admin.quiz_attempts.infolist.fields.points_awarded'))
                        ->state($answer->points_awarded !== null ? (string) $answer->points_awarded : '—'),
                ]);
        }

        return $infolist->schema([
            Infolists\Components\Section::make(__('admin.quiz_attempts.infolist.attempt'))
                ->schema([
                    Infolists\Components\TextEntry::make('user.name')->label(__('admin.quiz_attempts.infolist.fields.student')),
                    Infolists\Components\TextEntry::make('quiz.title')
                        ->label(__('admin.quiz_attempts.infolist.fields.quiz'))
                        ->state(fn () => (string) ($record->quiz?->getTranslation('title', app()->getLocale()) ?? '—')),
                    Infolists\Components\TextEntry::make('status')->label(__('admin.common.fields.status'))->badge(),
                    Infolists\Components\TextEntry::make('score')->label(__('admin.quiz_attempts.infolist.fields.score')),
                    Infolists\Components\TextEntry::make('max_score')->label(__('admin.quiz_attempts.infolist.fields.max_score')),
                    Infolists\Components\TextEntry::make('submitted_at')->label(__('admin.quiz_attempts.fields.submitted'))->dateTime(),
                ])
                ->columns(2),
            ...$answerEntries,
        ]);
    }
}
