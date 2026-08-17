<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartQuizRequest extends FormRequest
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
        return [];
    }
}
