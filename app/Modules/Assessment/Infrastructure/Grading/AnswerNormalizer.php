<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Grading;

final class AnswerNormalizer
{
    public static function text(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }

    /**
     * @param  list<string>|string|null  $accepted
     * @return list<string>
     */
    public static function acceptedList(array|string|null $accepted): array
    {
        if ($accepted === null) {
            return [];
        }

        if (is_string($accepted)) {
            $accepted = [$accepted];
        }

        return array_values(array_filter(array_map(
            fn ($v) => self::text(is_string($v) ? $v : (string) $v),
            $accepted
        )));
    }
}
