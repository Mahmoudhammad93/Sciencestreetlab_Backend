<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Application\Services\ProductReviewService;
use App\Modules\Catalog\Domain\Enums\ProductReviewStatus;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ProductReviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_submit_pending_review(): void
    {
        [$user, $product] = $this->userAndProduct();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/products/'.$product->slug.'/reviews', [
            'rating' => 5,
            'review' => 'Excellent science kit.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.rating', 5);

        $this->assertDatabaseHas('product_reviews', [
            'product_id' => $product->id,
            'user_id' => $user->id,
            'status' => ProductReviewStatus::Pending->value,
        ]);
    }

    public function test_guest_cannot_submit_review(): void
    {
        [, $product] = $this->userAndProduct();

        $this->postJson('/api/v1/products/'.$product->slug.'/reviews', [
            'rating' => 4,
            'review' => 'Nice kit',
        ])->assertUnauthorized();
    }

    public function test_rating_bounds_are_enforced(): void
    {
        [$user, $product] = $this->userAndProduct();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/products/'.$product->slug.'/reviews', [
            'rating' => 0,
            'review' => 'Too low',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/products/'.$product->slug.'/reviews', [
            'rating' => 6,
            'review' => 'Too high',
        ])->assertUnprocessable();
    }

    public function test_user_cannot_self_approve_via_payload(): void
    {
        [$user, $product] = $this->userAndProduct();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/products/'.$product->slug.'/reviews', [
            'rating' => 5,
            'review' => 'Trying to self approve',
            'status' => 'approved',
            'approved_at' => now()->toIso8601String(),
            'approved_by' => $user->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(
            ProductReviewStatus::Pending,
            ProductReview::query()->firstOrFail()->status
        );
    }

    public function test_duplicate_review_is_rejected(): void
    {
        [$user, $product] = $this->userAndProduct();
        Sanctum::actingAs($user);

        $payload = ['rating' => 4, 'review' => 'First review text'];
        $this->postJson('/api/v1/products/'.$product->slug.'/reviews', $payload)->assertCreated();
        $this->postJson('/api/v1/products/'.$product->slug.'/reviews', $payload)->assertUnprocessable();
    }

    public function test_product_details_returns_only_approved_reviews_and_summary(): void
    {
        [$user, $product] = $this->userAndProduct();
        $other = User::factory()->create();
        $admin = $this->adminUser();

        $pending = ProductReview::query()->create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'rating' => 2,
            'review' => 'Pending should hide',
            'status' => ProductReviewStatus::Pending,
        ]);
        $rejected = ProductReview::query()->create([
            'product_id' => $product->id,
            'user_id' => $other->id,
            'rating' => 1,
            'review' => 'Rejected should hide',
            'status' => ProductReviewStatus::Rejected,
        ]);
        $approvedUser = User::factory()->create(['name' => 'Approved Reviewer']);
        $approved = ProductReview::query()->create([
            'product_id' => $product->id,
            'user_id' => $approvedUser->id,
            'rating' => 5,
            'review' => 'Approved should show',
            'status' => ProductReviewStatus::Pending,
        ]);

        app(ProductReviewService::class)->approve($approved, $admin);

        $response = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();

        $reviews = $response->json('data.reviews');
        $this->assertCount(1, $reviews);
        $this->assertSame('Approved should show', $reviews[0]['review']);
        $this->assertSame('Approved Reviewer', $reviews[0]['user']['name']);
        $this->assertArrayNotHasKey('email', $reviews[0]['user']);
        $this->assertArrayNotHasKey('status', $reviews[0]);
        $this->assertArrayNotHasKey('approved_by', $reviews[0]);
        $this->assertSame(5.0, (float) $response->json('data.reviews_summary.average_rating'));
        $this->assertSame(1, $response->json('data.reviews_summary.reviews_count'));

        $encoded = json_encode($response->json('data'));
        $this->assertStringNotContainsString('Pending should hide', (string) $encoded);
        $this->assertStringNotContainsString('Rejected should hide', (string) $encoded);
        $this->assertNull($pending->fresh()->approved_at);
        $this->assertNull($rejected->fresh()->approved_at);
    }

    public function test_zero_approved_reviews_returns_empty_list_and_zero_summary(): void
    {
        [, $product] = $this->userAndProduct();
        $product->update(['average_rating' => 0, 'review_count' => 0]);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.reviews', [])
            ->assertJsonPath('data.reviews_summary.reviews_count', 0)
            ->assertJsonPath('data.reviews_summary.average_rating', 0);
    }

    public function test_admin_can_approve_and_reject(): void
    {
        [$user, $product] = $this->userAndProduct();
        $admin = $this->adminUser();
        $service = app(ProductReviewService::class);

        $review = ProductReview::query()->create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'rating' => 4,
            'review' => 'Needs moderation',
            'status' => ProductReviewStatus::Pending,
        ]);

        $approved = $service->approve($review, $admin);
        $this->assertSame(ProductReviewStatus::Approved, $approved->status);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame($admin->id, $approved->approved_by);
        $this->assertSame(1, $product->fresh()->review_count);

        $rejected = $service->reject($approved, $admin);
        $this->assertSame(ProductReviewStatus::Rejected, $rejected->status);
        $this->assertSame(0, $product->fresh()->review_count);
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function userAndProduct(): array
    {
        $user = User::factory()->create();
        $product = Product::query()->create([
            'sku' => 'REV-'.uniqid(),
            'slug' => 'review-kit-'.uniqid(),
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 100,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Review Kit', 'ar' => 'حقيبة'],
            'average_rating' => 0,
            'review_count' => 0,
        ]);

        return [$user, $product];
    }

    private function adminUser(): User
    {
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }
}
