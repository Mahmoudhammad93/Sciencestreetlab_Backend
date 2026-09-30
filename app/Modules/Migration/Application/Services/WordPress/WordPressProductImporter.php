<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WooCommerce product import (wp_ posts product CPT, prefix wp_ only).
 *
 * Dry-run writes NOTHING (no products, categories, or legacy_import_maps).
 * Real import gated on PRODUCT_SKU_REQUIRES_DECISION until
 * config('wordpress.product_sku_strategy') === 'wp_id'.
 */
final class WordPressProductImporter
{
    public const SKU_REQUIRES_DECISION = 'PRODUCT_SKU_REQUIRES_DECISION';

    public const VARIABLE_REQUIRES_DECISION = 'VARIABLE_PRODUCT_REQUIRES_DECISION';

    /** @var list<string> */
    private const BENEFIT_KEYS = [
        'why_should_you_buy_it',
        'why_should_you_buy_it1',
        'why_should_you_buy_it2',
        'why_should_you_buy_it3',
        'why_should_you_buy_it4',
        'why_should_you_buy_it5',
    ];

    /** @var list<string> */
    private const CONCEPT_KEYS = [
        'scientific_concepts',
        'scientific_concepts_1',
        'scientific_concepts2',
        'scientific_concepts3',
        'scientific_concepts4',
        'scientific_concepts5',
        'scientific_concepts6',
    ];

    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressApprovedCollisionMapper $approvedCollisions,
        private readonly WordPressRealPersistGate $persistGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(bool $dryRun = false): array
    {
        if ($block = $this->persistGate->importerBlockIfUnauthorized($dryRun, 'product')) {
            return $block;
        }

        $ready = $this->connection->assertReadyForImport('posts');
        if (! $ready['ok']) {
            return [
                'status' => 'blocked',
                'code' => $ready['reason'] ?? WordPressConnectionService::BLOCKED,
                'entity_type' => 'product',
                'dry_run' => $dryRun,
                'wrote_to_database' => false,
                'inspect' => $ready['inspect'],
            ];
        }

        $this->connection->assertProductionPrefix();

        $skuStrategy = config('wordpress.product_sku_strategy');
        if (! $dryRun && $skuStrategy !== 'wp_id') {
            return [
                'status' => 'blocked',
                'code' => self::SKU_REQUIRES_DECISION,
                'entity_type' => 'product',
                'dry_run' => false,
                'wrote_to_database' => false,
                'proposed_sku_strategy' => 'WP-{post_id}',
                'message' => 'All 31 published products have empty _sku. Approve wordpress.product_sku_strategy=wp_id before real import.',
                'inspect' => $ready['inspect'],
            ];
        }

        $conn = $this->connection->connectionName();
        $posts = $this->connection->table('posts');
        $postmeta = $this->connection->table('postmeta');

        $categoriesReport = $this->inventoryCategories($conn, $dryRun);

        $scanned = 0;
        $wouldCreate = 0;
        $wouldSkipMapped = 0;
        $wouldMapExisting = 0;
        $wouldFail = 0;
        $wouldDefer = 0;
        $variableBlocked = 0;
        $skuEmpty = 0;
        $courseResolved = 0;
        $courseUnresolved = 0;
        $courseAbsent = 0;
        $mediaPending = 0;
        $arName = 0;
        $enName = 0;
        $arDesc = 0;
        $enDesc = 0;
        $arShort = 0;
        $enShort = 0;
        $benefitsAr = 0;
        $conceptsAr = 0;
        $samples = [];
        $created = 0;
        $slugMergeRequired = 0;
        $slugMergeSamples = [];
        $variableIds = [];

        DB::connection($conn)
            ->table($posts)
            ->where('post_type', 'product')
            ->where('post_status', 'publish')
            ->orderBy('ID')
            ->select(['ID', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status'])
            ->chunkById(50, function ($rows) use (
                $dryRun,
                $conn,
                $postmeta,
                $skuStrategy,
                &$scanned,
                &$wouldCreate,
                &$wouldSkipMapped,
                &$wouldMapExisting,
                &$wouldFail,
                &$wouldDefer,
                &$variableBlocked,
                &$skuEmpty,
                &$courseResolved,
                &$courseUnresolved,
                &$courseAbsent,
                &$mediaPending,
                &$arName,
                &$enName,
                &$arDesc,
                &$enDesc,
                &$arShort,
                &$enShort,
                &$benefitsAr,
                &$conceptsAr,
                &$samples,
                &$created,
                &$slugMergeRequired,
                &$slugMergeSamples,
                &$variableIds,
                $categoriesReport,
            ): void {
                $ids = $rows->pluck('ID')->map(fn ($id) => (int) $id)->all();
                $metaByPost = $this->loadMetaForPosts($conn, $postmeta, $ids, [
                    '_price', '_regular_price', '_sale_price', '_sku',
                    '_stock', '_stock_status', '_manage_stock',
                    '_thumbnail_id', '_product_image_gallery',
                    '_related_course', '_difficult_level', 'difficult_level',
                    ...self::BENEFIT_KEYS,
                    ...self::CONCEPT_KEYS,
                ]);
                $types = $this->loadProductTypes($conn, $ids);
                $primaryCats = $this->loadPrimaryCategories($conn, $ids);

                foreach ($rows as $row) {
                    $scanned++;
                    $legacyId = (string) $row->ID;
                    $meta = $metaByPost[(int) $row->ID] ?? [];
                    $wcType = $types[(int) $row->ID] ?? 'simple';

                    if ($wcType === 'variable') {
                        $variableBlocked++;
                        $variableIds[] = $legacyId;
                        // Shared skip vocabulary (SKIPPED_VARIABLE_PRODUCT).
                        // Counted separately in variable_blocked; also tallied as would_skip.

                        continue;
                    }

                    $sku = trim((string) ($meta['_sku'] ?? ''));
                    if ($sku === '') {
                        $skuEmpty++;
                    }
                    $proposedSku = $this->resolveSku($sku !== '' ? $sku : null, $legacyId);

                    $related = $this->parseRelatedCourseIds($meta['_related_course'] ?? null);
                    $courseLegacyId = $related[0] ?? null;
                    $courseLocalId = null;
                    if ($courseLegacyId === null) {
                        $courseAbsent++;
                        $courseRelation = 'ABSENT';
                    } else {
                        $courseLocalId = $this->maps->find('course', (string) $courseLegacyId)?->local_id;
                        if ($courseLocalId) {
                            $courseResolved++;
                            $courseRelation = 'RESOLVED COURSE RELATION';
                        } else {
                            $courseUnresolved++;
                            $courseRelation = 'UNRESOLVED COURSE RELATION';
                        }
                    }

                    $thumbId = trim((string) ($meta['_thumbnail_id'] ?? ''));
                    $gallery = $this->parseGalleryIds($meta['_product_image_gallery'] ?? null);
                    if ($thumbId !== '' || $gallery !== []) {
                        $mediaPending++;
                    }

                    $nameAr = (string) $row->post_title;
                    $nameEn = $this->trpExactEnglish($conn, $nameAr);
                    if ($nameAr !== '') {
                        $arName++;
                    }
                    if ($nameEn !== null) {
                        $enName++;
                    }

                    $descAr = (string) $row->post_content;
                    $descEn = $descAr !== '' ? $this->trpExactEnglish($conn, $descAr) : null;
                    if (trim(strip_tags($descAr)) !== '') {
                        $arDesc++;
                    }
                    if ($descEn !== null) {
                        $enDesc++;
                    }

                    $shortAr = (string) $row->post_excerpt;
                    $shortEn = $shortAr !== '' ? $this->trpExactEnglish($conn, $shortAr) : null;
                    if (trim(strip_tags($shortAr)) !== '') {
                        $arShort++;
                    }
                    if ($shortEn !== null) {
                        $enShort++;
                    }

                    $benefits = $this->collectListMeta($meta, self::BENEFIT_KEYS);
                    $concepts = $this->collectListMeta($meta, self::CONCEPT_KEYS);
                    if ($benefits !== []) {
                        $benefitsAr++;
                    }
                    if ($concepts !== []) {
                        $conceptsAr++;
                    }

                    [$price, $compare] = $this->mapPrices($meta);
                    $proposedType = $this->proposeLaravelType($nameAr, (string) $row->post_name, $courseLegacyId !== null);

                    $payload = [
                        'legacy_id' => $legacyId,
                        'slug' => (string) $row->post_name,
                        'wc_type' => $wcType,
                        'proposed_laravel_type' => $proposedType,
                        'proposed_sku' => $proposedSku,
                        'sku_source' => $sku !== '' ? 'wp_sku' : 'proposed_WP-{id}',
                        'status' => ProductStatus::Published->value,
                        'price' => $price,
                        'compare_price' => $compare,
                        'stock_quantity' => $this->nullableInt($meta['_stock'] ?? null),
                        'manage_stock' => ($meta['_manage_stock'] ?? '') === 'yes',
                        'stock_status' => $meta['_stock_status'] ?? null,
                        'difficulty_level' => $this->resolveDifficulty($meta),
                        'name' => array_filter(['ar' => $nameAr, 'en' => $nameEn]),
                        'description' => array_filter(['ar' => $descAr !== '' ? $descAr : null, 'en' => $descEn]),
                        'short_description' => array_filter(['ar' => $shortAr !== '' ? $shortAr : null, 'en' => $shortEn]),
                        'key_benefits' => $benefits !== [] ? ['ar' => $benefits] : null,
                        'scientific_concepts' => $concepts !== [] ? ['ar' => $concepts] : null,
                        'course_relation' => $courseRelation,
                        'related_course_legacy_id' => $courseLegacyId,
                        'related_course_local_id' => $courseLocalId,
                        'category_term_id' => $primaryCats[(int) $row->ID] ?? null,
                        'media' => [
                            'status' => 'MEDIA_PENDING',
                            'featured_attachment_id' => $thumbId !== '' ? $thumbId : null,
                            'gallery_attachment_ids' => $gallery,
                            'featured_path' => $thumbId !== '' ? $this->attachmentRelativePath($conn, (int) $thumbId) : null,
                        ],
                    ];

                    $slug = $payload['slug'] !== '' ? $payload['slug'] : 'wp-'.$legacyId;
                    $existingMap = $this->maps->find('product', $legacyId);
                    $slugCollision = Product::query()->where('slug', $slug)->exists();
                    $approvedDecision = $this->approvedCollisions->productDecision($legacyId);
                    $outcome = MigrationImportOutcome::classifyProductShell(
                        $existingMap?->local_id !== null,
                        $slugCollision && $existingMap?->local_id === null,
                        false,
                        $approvedDecision,
                    );

                    match ($outcome) {
                        MigrationImportOutcome::WOULD_SKIP => $wouldSkipMapped++,
                        MigrationImportOutcome::WOULD_MAP_EXISTING => $wouldMapExisting++,
                        MigrationImportOutcome::WOULD_FAIL => $wouldFail++,
                        MigrationImportOutcome::WOULD_DEFER => $wouldDefer++,
                        default => $wouldCreate++,
                    };

                    if ($outcome === MigrationImportOutcome::WOULD_FAIL) {
                        $slugMergeRequired++;
                        if (count($slugMergeSamples) < 15) {
                            $slugMergeSamples[] = [
                                'legacy_id' => $legacyId,
                                'slug' => $slug,
                                'class' => 'MERGE_REQUIRES_DECISION',
                                'outcome' => $outcome,
                            ];
                        }
                    }

                    $payload['predicted_outcome'] = $outcome;

                    if ($dryRun && count($samples) < 8) {
                        $samples[] = $payload;
                    }

                    if (! $dryRun) {
                        $result = $this->persistProduct(
                            $payload,
                            $skuStrategy === 'wp_id' ? $proposedSku : $sku,
                            $categoriesReport,
                        );
                        if ($result === 'created') {
                            $created++;
                        }
                    }
                }
            }, 'ID');

        $readyForReal = $skuStrategy === 'wp_id' && $variableBlocked <= 1 && $slugMergeRequired === 0;
        $blockedBySlugMerge = ! $dryRun && $slugMergeRequired > 0 && $created === 0;

        return [
            'status' => $dryRun ? 'ok' : (
                $skuStrategy !== 'wp_id'
                    ? 'blocked'
                    : ($blockedBySlugMerge ? 'blocked' : ($created > 0 || $wouldSkipMapped > 0 ? 'ok' : 'blocked'))
            ),
            'code' => $dryRun ? null : (
                $skuStrategy !== 'wp_id'
                    ? self::SKU_REQUIRES_DECISION
                    : ($blockedBySlugMerge ? 'PRODUCT_SLUG_MERGE_REQUIRES_DECISION' : null)
            ),
            'entity_type' => 'product',
            'dry_run' => $dryRun,
            'published_products' => $scanned,
            'scanned_importable' => $scanned - $variableBlocked,
            'variable_blocked' => $variableBlocked,
            'variable_product_ids' => $variableIds,
            'slug_merge_requires_decision' => $slugMergeRequired,
            'slug_merge_samples' => $slugMergeSamples,
            'variable_note' => $variableIds !== []
                ? 'SKIPPED_VARIABLE_PRODUCT: product 6912 (Science Street Microscope) — first staging run excludes variable products'
                : null,
            'would_create' => $wouldCreate,
            'would_map_existing' => $wouldMapExisting,
            'would_skip' => $wouldSkipMapped + $variableBlocked,
            'would_skip_mapped' => $wouldSkipMapped,
            'would_defer' => $wouldDefer,
            'would_fail' => $wouldFail,
            'created' => $dryRun ? 0 : $created,
            'sku_empty' => $skuEmpty,
            'sku_decision' => 'APPROVED: WP-{legacy_product_id} for empty _sku; genuine SKU preserved',
            'proposed_sku_strategy' => 'WP-{post_id} when WORDPRESS_PRODUCT_SKU_STRATEGY=wp_id',
            'sku_policy' => [
                'approved' => true,
                'empty_sku' => 'WP-{legacy_product_id}',
                'non_empty_sku' => 'preserve source _sku',
                'real_import_requires_env' => 'WORDPRESS_PRODUCT_SKU_STRATEGY=wp_id',
            ],
            'variable_product' => [
                'id' => 6912,
                'policy' => 'SKIPPED_VARIABLE_PRODUCT',
                'flatten' => false,
                'analyzer' => 'WordPressVariableProductAnalyzer',
            ],
            'course_relations' => [
                'resolved' => $courseResolved,
                'unresolved' => $courseUnresolved,
                'absent' => $courseAbsent,
            ],
            'translations' => [
                'name_ar' => "{$arName}/{$scanned}",
                'name_en_confirmed' => "{$enName}/{$scanned}",
                'description_ar' => "{$arDesc}/{$scanned}",
                'description_en_confirmed' => "{$enDesc}/{$scanned}",
                'short_description_ar' => "{$arShort}/{$scanned}",
                'short_description_en_confirmed' => "{$enShort}/{$scanned}",
                'rule' => 'TranslatePress exact original-string match with status=2 only',
            ],
            'educational' => [
                'key_benefits' => ['confidence' => 'CONFIRMED', 'products_with_ar' => $benefitsAr],
                'scientific_concepts' => ['confidence' => 'CONFIRMED', 'products_with_ar' => $conceptsAr],
                'curricula' => ['confidence' => 'AMBIGUOUS', 'note' => 'Not mapped — structure differs from product_curriculum_alignments'],
            ],
            'categories' => $categoriesReport,
            'media_pending_products' => $mediaPending,
            'samples' => $samples,
            'wrote_to_database' => ! $dryRun && $created > 0,
            'ready_for_real_import' => $readyForReal && ! $dryRun ? true : ($dryRun ? false : false),
            'ready_for_real_import_after_approvals' => [
                'sku_strategy_wp_id' => $skuStrategy === 'wp_id',
                'variable_product_6912_skipped' => true,
                'uploads_directory' => false,
            ],
            'inspect' => $ready['inspect'],
        ];
    }

    /**
     * Persist helper for tests / authorized import. Never overwrites native slug rows.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $categoriesReport
     * @return 'created'|'skipped_mapped'|'merge_requires_decision'
     */
    public function persistProduct(array $payload, string $sku, array $categoriesReport = []): string
    {
        if ($this->maps->find('product', $payload['legacy_id'])?->local_id) {
            return 'skipped_mapped';
        }

        $slug = ($payload['slug'] ?? '') !== '' ? (string) $payload['slug'] : 'wp-'.$payload['legacy_id'];
        $existingBySlug = Product::query()->where('slug', $slug)->first();
        if ($existingBySlug !== null) {
            // Approved MAP_EXISTING: insert map only; never overwrite commercial fields.
            if ($this->approvedCollisions->productDecision((string) $payload['legacy_id']) === 'MAP_EXISTING') {
                $approvedSlug = $this->approvedCollisions->approvedProductLocalSlug((string) $payload['legacy_id']);
                if ($approvedSlug === null || $approvedSlug === $slug) {
                    $this->mapExistingProduct((string) $payload['legacy_id'], (int) $existingBySlug->id, [
                        'local_slug' => $slug,
                        'note' => 'approved_map_existing_during_persist',
                    ]);

                    return 'skipped_mapped';
                }
            }

            return 'merge_requires_decision';
        }

        $categoryLocalId = null;
        $termId = $payload['category_term_id'] ?? null;
        if ($termId !== null) {
            $categoryLocalId = $this->maps->find('category', (string) $termId)?->local_id;
        }

        $product = Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'sku' => $sku,
            'slug' => $slug,
            'type' => $payload['proposed_laravel_type'],
            'status' => ProductStatus::Published,
            'price' => $payload['price'] ?? 0,
            'compare_price' => $payload['compare_price'] ?? null,
            'currency' => 'EGP',
            'stock_quantity' => $payload['stock_quantity'] ?? null,
            'manage_stock' => (bool) ($payload['manage_stock'] ?? false),
            'is_featured' => false,
            'category_id' => $categoryLocalId,
            'related_course_id' => $payload['related_course_local_id'] ?? null,
            'difficulty_level' => $payload['difficulty_level'] ?? null,
            'name' => $payload['name'],
            'description' => ($payload['description'] ?? null) ?: null,
            'short_description' => ($payload['short_description'] ?? null) ?: null,
            'key_benefits' => $payload['key_benefits'] ?? null,
            'scientific_concepts' => $payload['scientific_concepts'] ?? null,
            'published_at' => now(),
        ]);

        $this->maps->upsertMapping('product', $payload['legacy_id'], [
            'local_id' => $product->id,
            'imported_at' => now(),
            'metadata' => LegacyImportMapRepository::ownershipCreated([
                'media' => $payload['media'] ?? ['status' => 'MEDIA_PENDING'],
                'related_course_legacy_id' => $payload['related_course_legacy_id'] ?? null,
                'wc_type' => $payload['wc_type'] ?? 'simple',
            ]),
        ]);

        return 'created';
    }

    /**
     * Map a WP product id onto a pre-existing Laravel product without overwriting it.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function mapExistingProduct(string $legacyId, int $localId, array $metadata = []): void
    {
        $this->maps->upsertMapping('product', $legacyId, [
            'local_id' => $localId,
            'imported_at' => now(),
            'metadata' => LegacyImportMapRepository::ownershipMappedExisting(array_merge([
                'collision_decision' => 'MAP_EXISTING',
                'overwrite' => false,
            ], $metadata)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryCategories(string $conn, bool $dryRun): array
    {
        $terms = DB::connection($conn)
            ->table($this->connection->table('terms').' as t')
            ->join($this->connection->table('term_taxonomy').' as tt', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'product_cat')
            ->orderBy('tt.parent')
            ->orderBy('t.term_id')
            ->get(['t.term_id', 't.name', 't.slug', 'tt.parent', 'tt.count']);

        $wouldCreate = 0;
        $wouldSkip = 0;
        $rows = [];
        foreach ($terms as $term) {
            $legacyId = (string) $term->term_id;
            $mapped = $this->maps->find('category', $legacyId)?->local_id;
            if ($mapped) {
                $wouldSkip++;
            } else {
                $wouldCreate++;
            }
            $rows[] = [
                'legacy_id' => $legacyId,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent_legacy_id' => (int) $term->parent > 0 ? (string) $term->parent : null,
                'count' => (int) $term->count,
            ];

            if (! $dryRun && ! $mapped) {
                $parentLocal = null;
                if ((int) $term->parent > 0) {
                    $parentLocal = $this->maps->find('category', (string) $term->parent)?->local_id;
                }
                $category = Category::query()->firstOrCreate(
                    ['slug' => $term->slug !== '' ? $term->slug : 'wp-cat-'.$legacyId],
                    [
                        'parent_id' => $parentLocal,
                        'name' => ['ar' => $term->name, 'en' => $term->name],
                        'is_active' => true,
                        'sort_order' => (int) $term->term_id,
                    ]
                );
                $this->maps->upsertMapping('category', $legacyId, [
                    'local_id' => $category->id,
                    'imported_at' => now(),
                    'metadata' => LegacyImportMapRepository::ownershipCreated([
                        'source' => 'product_cat',
                    ]),
                ]);
            }
        }

        return [
            'count' => count($rows),
            'hierarchy' => 'flat in dump (all parent=0)',
            'would_create' => $wouldCreate,
            'would_skip_mapped' => $wouldSkip,
            'terms' => $rows,
            'wrote_to_database' => false,
        ];
    }

    /**
     * @param  list<int>  $postIds
     * @param  list<string>  $keys
     * @return array<int, array<string, string|null>>
     */
    private function loadMetaForPosts(string $conn, string $postmeta, array $postIds, array $keys): array
    {
        $out = [];
        if ($postIds === []) {
            return $out;
        }
        foreach (DB::connection($conn)->table($postmeta)
            ->whereIn('post_id', $postIds)
            ->whereIn('meta_key', $keys)
            ->get(['post_id', 'meta_key', 'meta_value']) as $row) {
            $out[(int) $row->post_id][(string) $row->meta_key] = $row->meta_value !== null ? (string) $row->meta_value : null;
        }

        return $out;
    }

    /**
     * @param  list<int>  $postIds
     * @return array<int, string>
     */
    private function loadProductTypes(string $conn, array $postIds): array
    {
        $out = [];
        if ($postIds === []) {
            return $out;
        }
        $rows = DB::connection($conn)->select(
            'SELECT tr.object_id, t.name
             FROM '.$this->connection->physicalTable('term_relationships').' tr
             INNER JOIN '.$this->connection->physicalTable('term_taxonomy').' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN '.$this->connection->physicalTable('terms').' t ON t.term_id = tt.term_id
             WHERE tt.taxonomy = ? AND tr.object_id IN ('.implode(',', array_map('intval', $postIds)).')',
            ['product_type']
        );
        foreach ($rows as $row) {
            $out[(int) $row->object_id] = (string) $row->name;
        }

        return $out;
    }

    /**
     * @param  list<int>  $postIds
     * @return array<int, int>
     */
    private function loadPrimaryCategories(string $conn, array $postIds): array
    {
        $out = [];
        if ($postIds === []) {
            return $out;
        }
        $rows = DB::connection($conn)->select(
            'SELECT tr.object_id, t.term_id
             FROM '.$this->connection->physicalTable('term_relationships').' tr
             INNER JOIN '.$this->connection->physicalTable('term_taxonomy').' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN '.$this->connection->physicalTable('terms').' t ON t.term_id = tt.term_id
             WHERE tt.taxonomy = ? AND tr.object_id IN ('.implode(',', array_map('intval', $postIds)).')
             ORDER BY t.term_id ASC',
            ['product_cat']
        );
        foreach ($rows as $row) {
            $oid = (int) $row->object_id;
            if (! isset($out[$oid])) {
                $out[$oid] = (int) $row->term_id;
            }
        }

        return $out;
    }

    /**
     * Deterministic SKU: preserve genuine source SKU; otherwise WP-{legacyId}.
     */
    public function resolveSku(?string $sourceSku, string $legacyId): string
    {
        $sku = trim((string) $sourceSku);

        return $sku !== '' ? $sku : 'WP-'.$legacyId;
    }

    /**
     * @return list<int>
     */
    public function parseRelatedCourseIds(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (! is_array($decoded)) {
            if (is_numeric($raw)) {
                return [(int) $raw];
            }

            return [];
        }
        $ids = [];
        foreach ($decoded as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function parseGalleryIds(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== '' && ctype_digit($v)));
    }

    /**
     * @param  array<string, string|null>  $meta
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function collectListMeta(array $meta, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = trim(strip_tags((string) ($meta[$key] ?? '')));
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, string|null>  $meta
     * @return array{0: float|null, 1: float|null}
     */
    private function mapPrices(array $meta): array
    {
        $regular = $this->nullableFloat($meta['_regular_price'] ?? null);
        $sale = $this->nullableFloat($meta['_sale_price'] ?? null);
        $price = $this->nullableFloat($meta['_price'] ?? null);

        if ($sale !== null && $sale > 0) {
            return [$sale, $regular];
        }

        return [$price ?? $regular, null];
    }

    private function proposeLaravelType(string $title, string $slug, bool $hasRelatedCourse): string
    {
        $isCourseNamed = str_starts_with($title, 'كورس')
            || str_contains(strtolower($slug), 'course')
            || str_starts_with($title, 'The ') && str_contains($title, 'Course');

        if ($isCourseNamed && ! $hasRelatedCourse) {
            return ProductType::Course->value;
        }

        // Physical kits; related_course links learning content.
        return ProductType::Kit->value;
    }

    private function trpExactEnglish(string $conn, string $original): ?string
    {
        $original = trim($original);
        if ($original === '') {
            return null;
        }

        $row = DB::connection($conn)->selectOne(
            'SELECT d.translated
             FROM '.$this->connection->physicalTable('trp_original_strings').' o
             INNER JOIN '.$this->connection->physicalTable('trp_dictionary_ar_en_us').' d ON d.original_id = o.id
             WHERE o.original = ? AND d.status = 2 AND d.translated IS NOT NULL AND d.translated != ?
             LIMIT 1',
            [$original, '']
        );

        if ($row === null || trim((string) $row->translated) === '') {
            return null;
        }

        return (string) $row->translated;
    }

    private function attachmentRelativePath(string $conn, int $attachmentId): ?string
    {
        $file = DB::connection($conn)
            ->table($this->connection->table('postmeta'))
            ->where('post_id', $attachmentId)
            ->where('meta_key', '_wp_attached_file')
            ->value('meta_value');

        return $file !== null && $file !== '' ? (string) $file : null;
    }

    private function resolveDifficulty(array $meta): ?string
    {
        foreach (['difficult_level', '_difficult_level'] as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value === '' || str_starts_with($value, 'field_')) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
