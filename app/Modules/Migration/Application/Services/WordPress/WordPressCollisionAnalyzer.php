<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Competition\Infrastructure\Persistence\Models\Competition;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Illuminate\Support\Facades\DB;

/**
 * Read-only collision analysis between wordpress_legacy and current Laravel DB.
 *
 * Mapped legacy_import_maps rows are classified as SKIPPED_MAPPED (not MERGE).
 * No writes. No auto-merge.
 */
final class WordPressCollisionAnalyzer
{
    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(): array
    {
        $this->connection->assertProductionPrefix();
        $conn = $this->connection->connectionName();

        return [
            'users' => $this->users($conn),
            'products' => $this->products($conn),
            'categories' => $this->categories($conn),
            'courses' => $this->courses($conn),
            'orders' => $this->orders($conn),
            'competition' => $this->competition($conn),
            'wrote_to_database' => false,
        ];
    }

    /**
     * Pure classification helper (no WordPress connection). Used by tests + analyze().
     *
     * @return 'SKIPPED_MAPPED'|'MERGE_REQUIRES_DECISION'|'CONFLICT'|'NEW_RECORD'
     */
    public function classifyProductCollision(
        string $legacyId,
        string $slug,
        bool $slugExistsLocally,
        bool $proposedSkuExistsLocally,
    ): string {
        if ($this->maps->find('product', $legacyId)?->local_id) {
            return 'SKIPPED_MAPPED';
        }
        if ($slugExistsLocally) {
            return 'MERGE_REQUIRES_DECISION';
        }
        if ($proposedSkuExistsLocally) {
            return 'CONFLICT';
        }

        return 'NEW_RECORD';
    }

    /**
     * Pure classification helper for AQ competition vs local 100-photo rows.
     *
     * @return 'SKIPPED_MAPPED'|'MERGE_REQUIRES_DECISION'|'NEW_RECORD'
     */
    public function classifyCompetitionCollision(
        string $legacyId,
        bool $localHundredPhotoExists,
    ): string {
        if ($this->maps->find('competition', $legacyId)?->local_id) {
            return 'SKIPPED_MAPPED';
        }
        if ($localHundredPhotoExists) {
            return 'MERGE_REQUIRES_DECISION';
        }

        return 'NEW_RECORD';
    }

    /**
     * @return array<string, mixed>
     */
    private function users(string $conn): array
    {
        $emails = DB::connection($conn)
            ->table($this->connection->table('usermeta').' as um')
            ->join($this->connection->table('users').' as u', 'u.ID', '=', 'um.user_id')
            ->where('um.meta_key', 'wp_capabilities')
            ->pluck('u.user_email')
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->filter()
            ->unique();

        $safe = 0;
        $new = 0;
        foreach ($emails as $email) {
            if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $safe++;
            } else {
                $new++;
            }
        }

        return [
            'source' => $emails->count(),
            'SAFE_MATCH' => $safe,
            'MERGE_REQUIRES_DECISION' => 0,
            'CONFLICT' => 0,
            'NEW_RECORD' => $new,
            'policy' => 'SAFE_MATCH emails map legacy_import_maps→existing user; never duplicate; never overwrite password/roles/profile',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function products(string $conn): array
    {
        $rows = DB::connection($conn)->table($this->connection->table('posts'))
            ->where('post_type', 'product')
            ->where('post_status', 'publish')
            ->get(['ID', 'post_name', 'post_title']);

        $skippedMapped = 0;
        $slugMatch = 0;
        $skuMatch = 0;
        $new = 0;
        $slugSamples = [];
        foreach ($rows as $row) {
            $legacyId = (string) $row->ID;
            $slugHit = Product::query()->where('slug', $row->post_name)->exists();
            $skuHit = Product::query()->where('sku', 'WP-'.$row->ID)->exists();
            $class = $this->classifyProductCollision($legacyId, (string) $row->post_name, $slugHit, $skuHit);

            match ($class) {
                'SKIPPED_MAPPED' => $skippedMapped++,
                'MERGE_REQUIRES_DECISION' => $slugMatch++,
                'CONFLICT' => $skuMatch++,
                default => $new++,
            };

            if ($class === 'MERGE_REQUIRES_DECISION' && count($slugSamples) < 15) {
                $local = Product::query()->where('slug', $row->post_name)->first(['id', 'sku', 'slug', 'name']);
                $slugSamples[] = [
                    'legacy_id' => $legacyId,
                    'slug' => $row->post_name,
                    'wp_title' => $row->post_title,
                    'local_id' => $local?->id,
                    'local_sku' => $local?->sku,
                    'class' => 'MERGE_REQUIRES_DECISION',
                ];
            }
        }

        return [
            'source' => $rows->count(),
            'SAFE_MATCH' => 0,
            'SKIPPED_MAPPED' => $skippedMapped,
            'MERGE_REQUIRES_DECISION' => $slugMatch,
            'CONFLICT' => $skuMatch,
            'NEW_RECORD' => $new,
            'slug_overlap_samples' => $slugSamples,
            'policy' => 'Slug overlap without map → MERGE_REQUIRES_DECISION. Existing map → SKIPPED_MAPPED. Never auto-overwrite catalog.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categories(string $conn): array
    {
        $terms = DB::connection($conn)
            ->table($this->connection->table('terms').' as t')
            ->join($this->connection->table('term_taxonomy').' as tt', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'product_cat')
            ->get(['t.term_id', 't.slug', 't.name']);

        $match = 0;
        $skippedMapped = 0;
        $new = 0;
        foreach ($terms as $term) {
            $legacyId = (string) $term->term_id;
            if ($this->maps->find('category', $legacyId)?->local_id) {
                $skippedMapped++;

                continue;
            }
            if (Category::query()->where('slug', $term->slug)->exists()) {
                $match++;
            } else {
                $new++;
            }
        }

        return [
            'source' => $terms->count(),
            'SAFE_MATCH' => 0,
            'SKIPPED_MAPPED' => $skippedMapped,
            'MERGE_REQUIRES_DECISION' => $match,
            'CONFLICT' => 0,
            'NEW_RECORD' => $new,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function courses(string $conn): array
    {
        $rows = DB::connection($conn)->table($this->connection->table('posts'))
            ->where('post_type', 'sfwd-courses')
            ->where('post_status', 'publish')
            ->get(['ID', 'post_name', 'post_title']);

        $match = 0;
        $skippedMapped = 0;
        $new = 0;
        foreach ($rows as $row) {
            $legacyId = (string) $row->ID;
            if ($this->maps->find('course', $legacyId)?->local_id) {
                $skippedMapped++;

                continue;
            }
            if (Course::query()->where('slug', $row->post_name)->exists()) {
                $match++;
            } else {
                $new++;
            }
        }

        return [
            'source' => $rows->count(),
            'SAFE_MATCH' => 0,
            'SKIPPED_MAPPED' => $skippedMapped,
            'MERGE_REQUIRES_DECISION' => $match,
            'CONFLICT' => 0,
            'NEW_RECORD' => $new,
            'note' => 'Title match alone is never SAFE_MATCH for courses. WP 8507 (كورس الميكروسكوب) may need explicit MAP_EXISTING → local microscope-course even when slug differs.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orders(string $conn): array
    {
        $source = (int) DB::connection($conn)->table($this->connection->table('wc_orders'))->count();
        $existing = Order::query()->where('order_number', 'like', 'WP-%')->count();

        return [
            'source' => $source,
            'SAFE_MATCH' => 0,
            'MERGE_REQUIRES_DECISION' => 0,
            'CONFLICT' => $existing,
            'NEW_RECORD' => $source - $existing,
            'existing_WP_order_numbers' => $existing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function competition(string $conn): array
    {
        $aq = DB::connection($conn)->table($this->connection->table('aq_competitions'))->first();
        $local = Competition::query()->where('required_photos', 100)->get(['id', 'slug', 'status', 'title', 'prerequisite_course_id']);
        $legacyId = $aq ? (string) $aq->id : '0';
        $class = $aq
            ? $this->classifyCompetitionCollision($legacyId, $local->isNotEmpty())
            : 'NEW_RECORD';

        return [
            'source' => $aq ? 1 : 0,
            'SAFE_MATCH' => 0,
            'SKIPPED_MAPPED' => $class === 'SKIPPED_MAPPED' ? 1 : 0,
            'MERGE_REQUIRES_DECISION' => $class === 'MERGE_REQUIRES_DECISION' ? $local->count() : 0,
            'CONFLICT' => 0,
            'NEW_RECORD' => $class === 'NEW_RECORD' ? 1 : 0,
            'classification' => $class,
            'local_candidates' => $local->map(fn ($c) => [
                'id' => $c->id,
                'slug' => $c->slug,
                'status' => $c->status,
                'prerequisite_course_id' => $c->prerequisite_course_id,
            ])->all(),
            'aq' => $aq ? [
                'id' => (string) $aq->id,
                'title' => $aq->title,
                'required_images' => (int) $aq->required_images,
                'required_course_id' => (string) ($aq->required_course_id ?? ''),
            ] : null,
            'policy' => 'Existing unmapped 100-photo challenge → MERGE_REQUIRES_DECISION. Mapped → SKIPPED_MAPPED. Never auto-duplicate.',
            'related_course_caution' => 'MAP_EXISTING competition keeps local prerequisite (microscope-course). Also consider explicit course map 8507→microscope-course to avoid enrollment split.',
        ];
    }
}
