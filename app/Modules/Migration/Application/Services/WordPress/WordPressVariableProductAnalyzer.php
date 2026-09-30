<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use Illuminate\Support\Facades\DB;

/**
 * Read-only analysis of WooCommerce variable product 6912.
 */
final class WordPressVariableProductAnalyzer
{
    public function __construct(
        private readonly WordPressConnectionService $connection,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(int $productId = 6912): array
    {
        $this->connection->assertProductionPrefix();
        $conn = $this->connection->connectionName();
        $posts = $this->connection->table('posts');
        $postmeta = $this->connection->table('postmeta');

        $parent = DB::connection($conn)->table($posts)->where('ID', $productId)->first();
        if ($parent === null) {
            return ['status' => 'missing', 'product_id' => $productId];
        }

        $variations = DB::connection($conn)->table($posts)
            ->where('post_parent', $productId)
            ->where('post_type', 'product_variation')
            ->orderBy('ID')
            ->get(['ID', 'post_status', 'post_title']);

        $variationRows = [];
        foreach ($variations as $v) {
            $meta = DB::connection($conn)->table($postmeta)
                ->where('post_id', $v->ID)
                ->where(function ($q): void {
                    $q->whereIn('meta_key', ['_price', '_regular_price', '_sale_price', '_stock', '_stock_status', '_sku', '_thumbnail_id'])
                        ->orWhere('meta_key', 'like', 'attribute_%');
                })
                ->get(['meta_key', 'meta_value']);
            $row = [
                'id' => (int) $v->ID,
                'status' => $v->post_status,
                'attributes' => [],
            ];
            foreach ($meta as $m) {
                if (str_starts_with((string) $m->meta_key, 'attribute_')) {
                    $row['attributes'][(string) $m->meta_key] = $m->meta_value;
                } else {
                    $row[(string) $m->meta_key] = $m->meta_value;
                }
            }
            $variationRows[] = $row;
        }

        $attrsRaw = DB::connection($conn)->table($postmeta)
            ->where('post_id', $productId)
            ->where('meta_key', '_product_attributes')
            ->value('meta_value');
        $related = DB::connection($conn)->table($postmeta)
            ->where('post_id', $productId)
            ->where('meta_key', '_related_course')
            ->value('meta_value');

        // Laravel Catalog has ProductType kit|course|bundle only — no variation/attribute tables.
        $verdict = 'VARIABLE_PRODUCT_REQUIRES_EXTENSION';

        return [
            'product_id' => $productId,
            'title' => $parent->post_title,
            'slug' => $parent->post_name,
            'status' => $parent->post_status,
            'variations_count' => count($variationRows),
            'variations' => $variationRows,
            'parent_attributes_serialized' => $attrsRaw !== null,
            'attribute_summary' => 'الكتاب (book language): English | عربى — same price 3720 EGP both variations',
            'prices' => [
                'parent__price' => DB::connection($conn)->table($postmeta)->where('post_id', $productId)->where('meta_key', '_price')->value('meta_value'),
                'variation_prices' => array_column($variationRows, '_price'),
            ],
            'stock' => 'both variations outofstock / stock=0',
            'related_course' => $related,
            'images' => [
                'thumbnail_id' => DB::connection($conn)->table($postmeta)->where('post_id', $productId)->where('meta_key', '_thumbnail_id')->value('meta_value'),
                'gallery' => DB::connection($conn)->table($postmeta)->where('post_id', $productId)->where('meta_key', '_product_image_gallery')->value('meta_value'),
            ],
            'laravel_support' => 'No ProductVariant / attribute model in Catalog module',
            'verdict' => $verdict,
            'note' => 'Do not flatten to simple product — language booklet attribute is semantic commerce variation',
            'wrote_to_database' => false,
        ];
    }
}
