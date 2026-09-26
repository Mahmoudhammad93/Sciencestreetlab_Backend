<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductCurriculumAlignment;
use Illuminate\Support\Facades\DB;

final class ProductEducationalSpecSyncService
{
    /**
     * @param  list<array{
     *     grade_level?: string|array{en?: string, ar?: string},
     *     lesson_name?: string|array{en?: string, ar?: string},
     *     sort_order?: int
     * }>  $rows
     */
    public function syncCurriculumAlignments(Product $product, array $rows): void
    {
        DB::transaction(function () use ($product, $rows): void {
            $product->curriculumAlignments()->delete();

            $sort = 0;
            foreach ($rows as $row) {
                $grade = $this->normalizeLocalized($row['grade_level'] ?? null);
                $lesson = $this->normalizeLocalized($row['lesson_name'] ?? null);

                if ($grade === [] || $lesson === []) {
                    continue;
                }

                ProductCurriculumAlignment::query()->create([
                    'product_id' => $product->id,
                    'grade_level' => $grade,
                    'lesson_name' => $lesson,
                    'sort_order' => (int) ($row['sort_order'] ?? $sort),
                ]);
                $sort++;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function normalizeLocalized(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? [] : ['en' => $trimmed];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach (['en', 'ar'] as $locale) {
            if (! array_key_exists($locale, $value)) {
                continue;
            }
            $trimmed = trim((string) $value[$locale]);
            if ($trimmed !== '') {
                $out[$locale] = $trimmed;
            }
        }

        return $out;
    }
}
