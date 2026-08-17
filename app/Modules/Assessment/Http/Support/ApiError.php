<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Support;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class ApiError
{
    public static function fromDomain(DomainException $e): JsonResponse
    {
        $message = $e->getMessage();
        $code = 'ERROR';
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 422;

        if (str_contains($message, ':')) {
            [$codePart, $text] = explode(':', $message, 2);
            $code = trim($codePart);
            $message = trim($text) !== '' ? trim($text) : $message;
        }

        if ($code === 'INSUFFICIENT_QUESTIONS') {
            $status = 409;
        }

        return response()->json([
            'message' => $message,
            'code' => $code,
        ], $status);
    }

    public static function validation(ValidationException $e): JsonResponse
    {
        return response()->json([
            'message' => 'Validation failed',
            'code' => 'VALIDATION_ERROR',
            'errors' => $e->errors(),
        ], 422);
    }

    public static function make(string $message, string $code, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
