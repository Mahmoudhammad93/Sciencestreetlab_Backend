<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadQuizAnswerImageRequest extends FormRequest
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
            'image' => ['required', 'file', 'max:10240'],
            'replace' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'يجب اختيار صورة.',
            'image.file' => 'يجب اختيار صورة.',
            'image.max' => 'حجم الصورة يجب ألا يتجاوز 5 ميجابايت.',
        ];
    }
}
