<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Resources;

use App\Modules\Assessment\Application\Services\InteractiveQuestionStorageService;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

final class InteractiveQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $question = $this->resource;
        $expires = now()->addMinutes(60);
        $url = URL::temporarySignedRoute(
            'interactive.activity',
            $expires,
            ['uuid' => $question->uuid, 'path' => 'activity.html']
        );

        return [
            'question_id' => $question->id,
            'uuid' => $question->uuid,
            'interactive_type' => $question->interactive_type,
            'interactive_config' => $question->interactive_config,
            'url' => $url,
            'expires_at' => $expires->toIso8601String(),
            'sandbox' => 'allow-scripts',
            'protocol' => 'postMessage',
            'type' => QuestionType::InteractiveHtml->value,
        ];
    }
}
