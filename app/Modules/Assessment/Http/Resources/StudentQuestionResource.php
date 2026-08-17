<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Application\Services\InteractiveQuestionStorageService;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Question */
final class StudentQuestionResource extends JsonResource
{
    public function __construct($resource, private readonly bool $includeExplanation = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Question $question */
        $question = $this->resource;
        $locale = app()->getLocale();

        $data = [
            'id' => $question->id,
            'uuid' => $question->uuid,
            'type' => $question->question_type->value,
            'question_type' => $question->question_type->value,
            'difficulty' => $question->difficulty?->value,
            'points' => (float) $question->points,
            'body' => $question->getTranslation('body', $locale),
            'tags' => $question->relationLoaded('tags')
                ? $question->tags->map(fn ($t) => [
                    'id' => $t->id,
                    'slug' => $t->slug,
                    'name' => $t->getTranslation('name', $locale),
                ])->values()
                : [],
            'options' => $question->options->map(fn ($o) => [
                'id' => $o->id,
                'label' => $o->getTranslation('label', $locale),
                'sort_order' => $o->sort_order,
                'meta' => $this->publicMeta($o->meta),
            ])->values(),
        ];

        if ($this->includeExplanation) {
            $data['explanation'] = $question->getTranslation('explanation', $locale) ?: null;
        }

        if ($question->question_type === QuestionType::InteractiveHtml
            || $question->question_type === QuestionType::InteractiveActivity) {
            $storage = app(InteractiveQuestionStorageService::class);
            $activityUrl = $question->question_type === QuestionType::InteractiveHtml
                ? $storage->publicActivityUrl($question)
                : null;
            $data['interactive_type'] = $question->interactive_type;
            $data['interactive_url'] = $activityUrl;
            $data['interactive_config'] = $question->interactive_config;
            $data['interactive_activity_id'] = $question->interactive_activity_id;
            $data['interactive'] = [
                'type' => $question->interactive_type,
                'config' => $question->interactive_config,
                'activity_url' => $activityUrl,
                'activity_id' => $question->interactive_activity_id,
                'sandbox' => 'allow-scripts allow-forms',
                'protocol' => 'postMessage',
            ];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>|null
     */
    private function publicMeta(?array $meta): ?array
    {
        if ($meta === null) {
            return null;
        }

        // Never expose grading keys
        unset($meta['is_correct'], $meta['correct'], $meta['answer']);

        return $meta;
    }
}
