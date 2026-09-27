<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert legacy flat key_benefits lists to Spatie locale maps:
 * ["a","b"] → {"en":["a","b"],"ar":["a","b"]}
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->whereNotNull('key_benefits')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->key_benefits, true);
                    if (! is_array($decoded) || $decoded === []) {
                        continue;
                    }

                    // Already localized.
                    if (array_key_exists('en', $decoded) || array_key_exists('ar', $decoded)) {
                        continue;
                    }

                    if (! array_is_list($decoded)) {
                        continue;
                    }

                    $list = array_values(array_filter(
                        $decoded,
                        static fn ($item): bool => is_string($item) && $item !== ''
                    ));

                    DB::table('products')->where('id', $row->id)->update([
                        'key_benefits' => json_encode(
                            ['en' => $list, 'ar' => $list],
                            JSON_UNESCAPED_UNICODE
                        ),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('products')
            ->whereNotNull('key_benefits')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->key_benefits, true);
                    if (! is_array($decoded)) {
                        continue;
                    }

                    if (! array_key_exists('en', $decoded) && ! array_key_exists('ar', $decoded)) {
                        continue;
                    }

                    $list = $decoded['ar'] ?? $decoded['en'] ?? [];
                    if (! is_array($list)) {
                        $list = [];
                    }

                    DB::table('products')->where('id', $row->id)->update([
                        'key_benefits' => json_encode(
                            array_values(array_filter(
                                $list,
                                static fn ($item): bool => is_string($item) && $item !== ''
                            )),
                            JSON_UNESCAPED_UNICODE
                        ),
                    ]);
                }
            });
    }
};
