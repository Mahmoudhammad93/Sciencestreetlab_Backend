<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitInteractiveActivityProgressRequest extends FormRequest
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
            'completed_challenges' => ['required', 'integer', 'min:0'],
            'total_challenges' => ['required', 'integer', 'min:1'],
            'percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
