<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitInteractiveActivityResultRequest extends FormRequest
{
    use FormatsApiValidationErrors;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'completed' => ['sometimes', 'boolean'],
            'time_spent_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'clientScore' => ['sometimes', 'nullable', 'numeric'],
            'score' => ['sometimes', 'nullable', 'numeric'],
            'max_score' => ['sometimes', 'nullable', 'numeric'],
            'percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'challenges_completed' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'total_challenges' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'result' => ['sometimes', 'array'],
            'result.score' => ['sometimes', 'nullable', 'numeric'],
            'result.max_score' => ['sometimes', 'nullable', 'numeric'],
            'result.percentage' => ['sometimes', 'nullable', 'numeric'],
            'result.time_spent_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'result.completed' => ['sometimes', 'boolean'],
            'result.challenges_completed' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'result.total_challenges' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'result.answers' => ['sometimes', 'array'],
        ];
    }
}
