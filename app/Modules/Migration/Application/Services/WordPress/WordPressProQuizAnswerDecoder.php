<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

/**
 * Safe decoder for LearnDash Pro Quiz answer_data (WpProQuiz_Model_AnswerTypes).
 * Never instantiates arbitrary classes from serialized payloads.
 */
final class WordPressProQuizAnswerDecoder
{
    /**
     * @return list<array{
     *     answer: string,
     *     html: bool,
     *     points: float,
     *     correct: bool,
     *     sort_string: string,
     *     sort_string_html: bool,
     *     graded: mixed,
     *     graded_type: mixed,
     *     grading_progression: mixed
     * }>|null null when payload is malformed
     */
    public function decode(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        try {
            $decoded = @unserialize($raw, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }

        if ($decoded === false && $raw !== 'b:0;') {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $out = [];
        foreach ($decoded as $item) {
            if (! is_object($item) && ! is_array($item)) {
                return null;
            }
            $norm = $this->normalizeObject((array) $item);
            $out[] = [
                'answer' => (string) ($norm['_answer'] ?? ''),
                'html' => (bool) ($norm['_html'] ?? false),
                'points' => (float) ($norm['_points'] ?? 0),
                'correct' => (bool) ($norm['_correct'] ?? false),
                'sort_string' => (string) ($norm['_sortString'] ?? ''),
                'sort_string_html' => (bool) ($norm['_sortStringHtml'] ?? false),
                'graded' => $norm['_graded'] ?? null,
                'graded_type' => $norm['_gradedType'] ?? null,
                'grading_progression' => $norm['_gradingProgression'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string|int, mixed>  $arr
     * @return array<string, mixed>
     */
    private function normalizeObject(array $arr): array
    {
        $norm = [];
        foreach ($arr as $key => $value) {
            $k = (string) $key;
            // Strip PHP protected/private serialization prefixes: "\0*\0_correct"
            $k = preg_replace('/^\x00[^\x00]*\x00/', '', $k) ?? $k;
            if ($k === '__PHP_Incomplete_Class_Name') {
                continue;
            }
            $norm[$k] = $value;
        }

        return $norm;
    }
}
