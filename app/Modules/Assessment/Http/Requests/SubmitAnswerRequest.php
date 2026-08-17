<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitAnswerRequest extends FormRequest
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
            'question_id' => ['required', 'integer'],
            'answer' => ['required', 'array'],
            'answer.option_id' => ['sometimes', 'integer'],
            'answer.option_ids' => ['sometimes', 'array'],
            'answer.option_ids.*' => ['integer'],
            'answer.text' => ['sometimes', 'string'],
            'answer.numeric' => ['sometimes', 'numeric'],
            'answer.matches' => ['sometimes', 'array'],
            'answer.order' => ['sometimes', 'array'],
            'answer.order.*' => ['integer'],
            'answer.result' => ['sometimes', 'array'],
            'answer.interaction_data' => ['sometimes', 'array'],
            'answer.blanks' => ['sometimes', 'array'],
            // Legacy flat payload support
            'selected_option_ids' => ['sometimes', 'array'],
            'text_answer' => ['sometimes', 'string'],
            'numeric_answer' => ['sometimes', 'numeric'],
            'matching_answer' => ['sometimes', 'array'],
            'ordering_answer' => ['sometimes', 'array'],
            'interactive_answer' => ['sometimes', 'array'],
            'client_result' => ['sometimes', 'array'],
        ];
    }
}
