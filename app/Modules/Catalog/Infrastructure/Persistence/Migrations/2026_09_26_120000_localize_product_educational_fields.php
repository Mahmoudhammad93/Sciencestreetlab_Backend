<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert product educational fields to Spatie-translatable JSON safely.
 * Existing scalar/list values become {"en": <old>} without destroying data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $alignments = DB::table('product_curriculum_alignments')
            ->select('id', 'grade_level', 'lesson_name', 'product_id', 'sort_order', 'created_at', 'updated_at')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'grade_level' => $this->wrapLocalizedScalar($row->grade_level),
                'lesson_name' => $this->wrapLocalizedScalar($row->lesson_name),
                'sort_order' => $row->sort_order,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])
            ->all();

        Schema::table('product_curriculum_alignments', function (Blueprint $table): void {
            $table->dropColumn(['grade_level', 'lesson_name']);
        });

        Schema::table('product_curriculum_alignments', function (Blueprint $table): void {
            $table->json('grade_level')->nullable()->after('product_id');
            $table->json('lesson_name')->nullable()->after('grade_level');
        });

        foreach ($alignments as $row) {
            DB::table('product_curriculum_alignments')->where('id', $row['id'])->update([
                'grade_level' => $row['grade_level'],
                'lesson_name' => $row['lesson_name'],
            ]);
        }

        $products = DB::table('products')
            ->select('id', 'scientific_concepts', 'design_lab_description', 'creative_lab_description')
            ->get();

        foreach ($products as $product) {
            DB::table('products')->where('id', $product->id)->update([
                'scientific_concepts' => $this->wrapScientificConcepts($product->scientific_concepts),
                'design_lab_description' => $this->wrapLocalizedScalar($product->design_lab_description),
                'creative_lab_description' => $this->wrapLocalizedScalar($product->creative_lab_description),
            ]);
        }
    }

    public function down(): void
    {
        $products = DB::table('products')
            ->select('id', 'scientific_concepts', 'design_lab_description', 'creative_lab_description')
            ->get();

        foreach ($products as $product) {
            DB::table('products')->where('id', $product->id)->update([
                'scientific_concepts' => $this->unwrapScientificConcepts($product->scientific_concepts),
                'design_lab_description' => $this->unwrapLocalizedScalar($product->design_lab_description),
                'creative_lab_description' => $this->unwrapLocalizedScalar($product->creative_lab_description),
            ]);
        }

        $alignments = DB::table('product_curriculum_alignments')
            ->select('id', 'grade_level', 'lesson_name')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'grade_level' => $this->unwrapLocalizedScalar($row->grade_level) ?? '',
                'lesson_name' => $this->unwrapLocalizedScalar($row->lesson_name) ?? '',
            ])
            ->all();

        Schema::table('product_curriculum_alignments', function (Blueprint $table): void {
            $table->dropColumn(['grade_level', 'lesson_name']);
        });

        Schema::table('product_curriculum_alignments', function (Blueprint $table): void {
            $table->string('grade_level', 100)->after('product_id');
            $table->string('lesson_name', 255)->after('grade_level');
        });

        foreach ($alignments as $row) {
            DB::table('product_curriculum_alignments')->where('id', $row['id'])->update([
                'grade_level' => $row['grade_level'],
                'lesson_name' => $row['lesson_name'],
            ]);
        }
    }

    private function wrapLocalizedScalar(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && $this->looksLocalized($decoded)) {
                return json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }

            return json_encode(['en' => $value], JSON_UNESCAPED_UNICODE);
        }

        return json_encode(['en' => (string) $value], JSON_UNESCAPED_UNICODE);
    }

    private function unwrapLocalizedScalar(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return (string) $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && $this->looksLocalized($decoded)) {
            $picked = $decoded['en'] ?? $decoded['ar'] ?? reset($decoded);

            return is_scalar($picked) ? (string) $picked : null;
        }

        return $value;
    }

    private function wrapScientificConcepts(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            return is_string($value)
                ? json_encode(['en' => [$value]], JSON_UNESCAPED_UNICODE)
                : null;
        }

        if ($this->looksLocalized($decoded)) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        return json_encode(['en' => array_values($decoded)], JSON_UNESCAPED_UNICODE);
    }

    private function unwrapScientificConcepts(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            return null;
        }

        if ($this->looksLocalized($decoded)) {
            $list = $decoded['en'] ?? $decoded['ar'] ?? reset($decoded);

            return json_encode(is_array($list) ? array_values($list) : [], JSON_UNESCAPED_UNICODE);
        }

        return json_encode(array_values($decoded), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<mixed>  $decoded
     */
    private function looksLocalized(array $decoded): bool
    {
        return array_key_exists('en', $decoded) || array_key_exists('ar', $decoded);
    }
};
