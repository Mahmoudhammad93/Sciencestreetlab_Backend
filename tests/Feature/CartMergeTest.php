<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\CartService;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CartMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bearer_token_cart_persists_for_logged_in_user(): void
    {
        $this->seed();

        $user = User::factory()->create();
        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();
        $token = $user->createToken('test')->plainTextToken;

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertCreated();

        $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
        $this->assertSame(1, Cart::query()->where('user_id', $user->id)->firstOrFail()->items()->count());

        $this->getJson('/api/v1/cart', [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.item_count', 1);
    }

    public function test_session_cart_merges_into_user_cart_when_authenticated(): void
    {
        $this->seed();

        $product = Product::query()->where('sku', 'SS-MICRO-001')->firstOrFail();
        $user = User::factory()->create();
        $cartService = app(CartService::class);

        $sessionCart = Cart::query()->create([
            'session_id' => 'guest-session-123',
            'expires_at' => now()->addDays(7),
        ]);
        $cartService->addItem($sessionCart, $product, 1);

        $userCart = $cartService->mergeSessionCartIntoUserCart($user, 'guest-session-123');

        $this->assertSame(1, $userCart->items()->count());
        $this->assertDatabaseMissing('carts', ['session_id' => 'guest-session-123']);
        $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
    }
}
