<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Requests;

use App\Modules\Assessment\Http\Support\ApiError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

trait FormatsApiValidationErrors
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiError::validation(ValidationException::withMessages($validator->errors()->toArray()))
        );
    }
}
