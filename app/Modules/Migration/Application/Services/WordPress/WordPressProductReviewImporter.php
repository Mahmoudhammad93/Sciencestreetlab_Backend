<?php

declare(strict_types=1);

namespace App\Modules\Migration\Application\Services\WordPress;

use App\Modules\Catalog\Application\Services\ProductReviewService;
use App\Modules\Catalog\Domain\Enums\ProductReviewStatus;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductReview;
use Illuminate\Support\Facades\DB;

/**
 * Historical WooCommerce product reviews → product_reviews.
 *
 * Destination: config wordpress.reviews.destination_product_slug
 * (6912 reviews → existing science-street-microscope-2 kit; variable parent stays skipped).
 *
 * Guests: user_id=null + guest_display_name (Option A). No synthetic users. No emails stored.
 * Live API auth rules are unchanged.
 */
final class WordPressProductReviewImporter
{
    public const ENTITY_TYPE = 'product_review';

    public function __construct(
        private readonly WordPressConnectionService $connection,
        private readonly LegacyImportMapRepository $maps,
        private readonly WordPressRealPersistGate $persistGate,
        private readonly ProductReviewService $reviewService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function import(bool $dryRun = true): array
    {
        if ($block = $this->persistGate->importerBlockIfUnauthorized($dryRun, 'product_review')) {
            return $block;
        }

        $ready = $this->connection->assertReadyForImport('comments');
        if (! $ready['ok']) {
            return [
                'status' => 'blocked',
                'code' => 'WORDPRESS_NOT_READY',
                'dry_run' => $dryRun,
                'wrote_to_database' => false,
                'inspect' => $ready['inspect'] ?? null,
            ];
        }

        $legacyProductId = (string) config('wordpress.reviews.source_product_legacy_id', '6912');
        $destinationSlug = (string) config('wordpress.reviews.destination_product_slug', 'science-street-microscope-2');
        $product = Product::query()->where('slug', $destinationSlug)->first();
        if ($product === null) {
            return [
                'status' => 'blocked',
                'code' => 'DESTINATION_PRODUCT_MISSING',
                'destination_product_slug' => $destinationSlug,
                'dry_run' => $dryRun,
                'wrote_to_database' => false,
            ];
        }

        $conn = $this->connection->connectionName();
        $commentsTable = $this->connection->table('comments');
        $metaTable = $this->connection->table('commentmeta');

        $rows = DB::connection($conn)
            ->table($commentsTable)
            ->where('comment_type', 'review')
            ->where('comment_post_ID', (int) $legacyProductId)
            ->orderBy('comment_ID')
            ->get();

        $wouldCreate = 0;
        $wouldSkipMapped = 0;
        $wouldSkipMissingRating = 0;
        $wouldDeferUser = 0;
        $created = 0;
        $skippedMapped = 0;
        $skippedMissingRating = 0;
        $deferredUser = 0;
        $guestWould = 0;
        $registeredWould = 0;
        $samples = [];

        foreach ($rows as $row) {
            $legacyId = (string) $row->comment_ID;
            $existing = $this->maps->find(self::ENTITY_TYPE, $legacyId);
            if ($existing?->local_id) {
                $wouldSkipMapped++;
                if (! $dryRun) {
                    $skippedMapped++;
                }

                continue;
            }

            $rating = DB::connection($conn)
                ->table($metaTable)
                ->where('comment_id', $row->comment_ID)
                ->where('meta_key', 'rating')
                ->value('meta_value');
            if ($rating === null || $rating === '' || (int) $rating < 1 || (int) $rating > 5) {
                $wouldSkipMissingRating++;
                if (! $dryRun) {
                    $skippedMissingRating++;
                }

                continue;
            }

            $wpUserId = (int) ($row->user_id ?? 0);
            $isGuest = $wpUserId <= 0;
            $localUserId = null;
            if (! $isGuest) {
                $localUserId = $this->maps->find('user', (string) $wpUserId)?->local_id;
                if ($localUserId === null) {
                    $wouldDeferUser++;
                    if (! $dryRun) {
                        $deferredUser++;
                    }

                    continue;
                }
            }

            $verifiedRaw = DB::connection($conn)
                ->table($metaTable)
                ->where('comment_id', $row->comment_ID)
                ->where('meta_key', 'verified')
                ->value('meta_value');
            $verified = $verifiedRaw === null ? null : ((string) $verifiedRaw === '1');

            $status = $this->mapStatus((string) $row->comment_approved);
            $displayName = $isGuest
                ? mb_substr(trim((string) ($row->comment_author ?? '')), 0, 191)
                : null;

            if ($isGuest) {
                $guestWould++;
            } else {
                $registeredWould++;
            }
            $wouldCreate++;

            if (count($samples) < 5) {
                $samples[] = [
                    'legacy_comment_id' => $legacyId,
                    'guest' => $isGuest,
                    'has_local_user' => $localUserId !== null,
                    'rating' => (int) $rating,
                    'status' => $status->value,
                    'verified' => $verified,
                    'content_len' => mb_strlen((string) $row->comment_content),
                ];
            }

            if ($dryRun) {
                continue;
            }

            $review = ProductReview::query()->create([
                'product_id' => $product->id,
                'user_id' => $localUserId,
                'is_legacy_guest' => $isGuest,
                'guest_display_name' => $isGuest ? ($displayName !== '' ? $displayName : null) : null,
                'is_verified_purchase' => $verified,
                'rating' => (int) $rating,
                'review' => (string) $row->comment_content,
                'status' => $status,
                'approved_at' => $status === ProductReviewStatus::Approved
                    ? ($row->comment_date ?? now())
                    : null,
                'approved_by' => null,
                'created_at' => $row->comment_date ?? now(),
                'updated_at' => $row->comment_date_gmt ?? $row->comment_date ?? now(),
            ]);

            $this->maps->upsertMapping(self::ENTITY_TYPE, $legacyId, [
                'local_id' => $review->id,
                'imported_at' => now(),
                'metadata' => LegacyImportMapRepository::ownershipCreated([
                    'source' => 'wp_comments',
                    'comment_type' => 'review',
                    'legacy_product_id' => $legacyProductId,
                    'destination_product_id' => $product->id,
                    'destination_product_slug' => $destinationSlug,
                    'legacy_user_id' => $isGuest ? null : (string) $wpUserId,
                    'is_legacy_guest' => $isGuest,
                    'is_verified_purchase' => $verified,
                    'mapped_status' => $status->value,
                    // Never store raw email / IP / agent in maps.
                ]),
            ]);
            $created++;
        }

        if (! $dryRun && $created > 0) {
            $this->reviewService->recalculateProductRatings($product->id);
        }

        return [
            'status' => 'ok',
            'dry_run' => $dryRun,
            'wrote_to_database' => ! $dryRun && $created > 0,
            'source_product_legacy_id' => $legacyProductId,
            'destination_product_id' => $product->id,
            'destination_product_slug' => $destinationSlug,
            'destination_policy' => config('wordpress.reviews.destination_policy'),
            'guest_strategy' => config('wordpress.reviews.guest_strategy'),
            'source_total' => $rows->count(),
            'would_create' => $dryRun ? $wouldCreate : 0,
            'would_create_guest' => $dryRun ? $guestWould : 0,
            'would_create_registered' => $dryRun ? $registeredWould : 0,
            'would_skip_mapped' => $dryRun ? $wouldSkipMapped : 0,
            'would_skip_missing_rating' => $dryRun ? $wouldSkipMissingRating : 0,
            'would_defer_unmapped_user' => $dryRun ? $wouldDeferUser : 0,
            'created' => $dryRun ? 0 : $created,
            'skipped_mapped' => $dryRun ? 0 : $skippedMapped,
            'skipped_missing_rating' => $dryRun ? 0 : $skippedMissingRating,
            'deferred_unmapped_user' => $dryRun ? 0 : $deferredUser,
            'samples' => $samples,
            'side_effects' => [
                'email' => false,
                'synthetic_users' => false,
                'media' => false,
                'quiz' => false,
                'live_anonymous_review_api' => false,
            ],
            'inspect' => $ready['inspect'] ?? null,
        ];
    }

    private function mapStatus(string $approved): ProductReviewStatus
    {
        return match ($approved) {
            '1' => ProductReviewStatus::Approved,
            'spam', 'trash' => ProductReviewStatus::Rejected,
            default => ProductReviewStatus::Pending,
        };
    }
}
