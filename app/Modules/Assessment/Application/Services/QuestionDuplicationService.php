<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Application\Services;

use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class QuestionDuplicationService
{
    public function __construct(
        private readonly InteractiveQuestionStorageService $interactiveStorage,
    ) {}

    public function duplicate(Question $source): Question
    {
        return DB::transaction(function () use ($source) {
            $source->load(['options', 'tags']);

            $copy = $source->replicate([
                'uuid',
            ]);
            $copy->uuid = (string) Str::uuid();
            $copy->status = QuestionStatus::Draft;
            $copy->interactive_path = null;
            $copy->save();

            foreach ($source->options as $option) {
                /** @var QuestionOption $option */
                $optCopy = $option->replicate();
                $optCopy->question_id = $copy->id;
                $optCopy->save();
            }

            if ($source->tags->isNotEmpty()) {
                $copy->tags()->sync($source->tags->pluck('id'));
            }

            if ($source->question_type->value === 'interactive_html') {
                $this->interactiveStorage->duplicateStorage($source, $copy);
            }

            return $copy->fresh(['options', 'tags', 'bank']);
        });
    }
}
