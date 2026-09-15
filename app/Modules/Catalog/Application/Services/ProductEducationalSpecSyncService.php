<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductCurriculumAlignment;
use Illuminate\Support\Facades\DB;

final class ProductEducationalSpecSyncService
{
    /**
     * @param  list<array{grade_level?: string, lesson_name?: string, sort_order?: int}>  $rows
     */
    public function syncCurriculumAlignments(Product $product, array $rows): void
    {
        DB::transaction(function () use ($product, $rows): void {
            $product->curriculumAlignments()->delete();

            $sort = 0;
            foreach ($rows as $row) {
                $grade = trim((string) ($row['grade_level'] ?? ''));
                $lesson = trim((string) ($row['lesson_name'] ?? ''));

                if ($grade === '' || $lesson === '') {
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
}
