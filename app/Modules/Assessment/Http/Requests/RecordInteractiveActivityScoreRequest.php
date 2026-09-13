<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RecordInteractiveActivityScoreRequest extends FormRequest
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
            'score' => ['required', 'numeric', 'min:0'],
            'max_score' => ['required_without:maxScore', 'numeric', 'gt:0'],
            'maxScore' => ['required_without:max_score', 'numeric', 'gt:0'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'durationSeconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'result' => ['sometimes', 'nullable', 'string', 'in:completed,abandoned'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'percentage' => ['sometimes', 'nullable', 'numeric'],
        ];
    }
}
