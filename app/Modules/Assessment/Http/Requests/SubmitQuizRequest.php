<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitQuizRequest extends FormRequest
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
            'answers' => ['sometimes', 'array'],
            'answers.*.question_id' => ['required_with:answers', 'integer'],
            'answers.*.answer' => ['sometimes', 'array'],
            'answers.*.selected_option_ids' => ['sometimes', 'array'],
            'answers.*.text_answer' => ['sometimes', 'string'],
            'answers.*.numeric_answer' => ['sometimes', 'numeric'],
            'answers.*.matching_answer' => ['sometimes', 'array'],
            'answers.*.ordering_answer' => ['sometimes', 'array'],
            'answers.*.interactive_answer' => ['sometimes', 'array'],
            'answers.*.client_result' => ['sometimes', 'array'],
        ];
    }
}
