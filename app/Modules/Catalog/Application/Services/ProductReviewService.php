<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductReviewStatus;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductReviewService
{
    /**
     * @param  array{rating: int, review: string}  $data
     */
    public function submit(Product $product, User $user, array $data): ProductReview
    {
        if (ProductReview::query()
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'review' => ['You have already reviewed this product.'],
            ]);
        }

        return ProductReview::query()->create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'rating' => (int) $data['rating'],
            'review' => trim((string) $data['review']),
            'status' => ProductReviewStatus::Pending,
        ]);
    }

    public function approve(ProductReview $review, User $admin): ProductReview
    {
        return DB::transaction(function () use ($review, $admin): ProductReview {
            /** @var ProductReview $locked */
            $locked = ProductReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'status' => ProductReviewStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $admin->id,
            ]);

            $this->recalculateProductRatings($locked->product_id);

            return $locked->fresh(['user', 'product']) ?? $locked;
        });
    }

    public function reject(ProductReview $review, ?User $admin = null): ProductReview
    {
        return DB::transaction(function () use ($review, $admin): ProductReview {
            /** @var ProductReview $locked */
            $locked = ProductReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'status' => ProductReviewStatus::Rejected,
                'approved_at' => null,
                'approved_by' => $admin?->id,
            ]);

            $this->recalculateProductRatings($locked->product_id);

            return $locked->fresh(['user', 'product']) ?? $locked;
        });
    }

    public function recalculateProductRatings(int $productId): void
    {
        $stats = ProductReview::query()
            ->where('product_id', $productId)
            ->where('status', ProductReviewStatus::Approved->value)
            ->selectRaw('COUNT(*) as reviews_count, COALESCE(AVG(rating), 0) as average_rating')
            ->first();

        Product::query()->whereKey($productId)->update([
            'review_count' => (int) ($stats->reviews_count ?? 0),
            'average_rating' => round((float) ($stats->average_rating ?? 0), 2),
        ]);
    }
}
