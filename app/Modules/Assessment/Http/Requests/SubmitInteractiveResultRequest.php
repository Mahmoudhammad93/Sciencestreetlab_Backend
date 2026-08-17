<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitInteractiveResultRequest extends FormRequest
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
            'result' => ['sometimes', 'array'],
            'interaction_data' => ['sometimes', 'array'],
            'completed' => ['sometimes', 'boolean'],
            'clientScore' => ['sometimes', 'numeric'], // accepted but never trusted
        ];
    }
}
