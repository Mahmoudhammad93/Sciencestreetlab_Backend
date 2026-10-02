<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CustomerOrderCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_cancel_unpaid_awaiting_payment_order(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user->id, OrderStatus::AwaitingPayment->value);

        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders/'.$order->order_number.'/cancel', [
                'reason' => 'غيرت رأيي',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_allowed', false);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled->value, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertStringContainsString('[customer_cancel]', (string) $order->notes);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertSame(0, Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Refunded->value)->count());
        $this->assertSame(0, Shipment::query()->where('order_id', $order->id)->count());
    }

    public function test_cannot_cancel_another_users_order(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $order = $this->makeOrder($owner->id, OrderStatus::AwaitingPayment->value);
        $token = $other->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders/'.$order->order_number.'/cancel')
            ->assertNotFound();

        $this->assertSame(OrderStatus::AwaitingPayment->value, $order->fresh()->status);
    }

    public function test_bearer_customer_wins_over_admin_web_session_for_cancel(): void
    {
        $admin = User::factory()->create(['email' => 'admin-cancel@sciencestreetlab.com']);
        $customer = User::factory()->create(['email' => 'customer-cancel@example.com']);
        $order = $this->makeOrder($customer->id, OrderStatus::AwaitingPayment->value);

        $this->actingAs($admin, 'web');
        $this->assertSame($admin->id, Auth::guard('web')->id());

        $token = $customer->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders/'.$order->order_number.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.user_id', $customer->id);

        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->status);
    }

    public function test_paid_order_cannot_be_customer_cancelled(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user->id, OrderStatus::Paid->value, paid: true);
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'fawaterak',
            'status' => PaymentStatus::Completed->value,
            'amount' => $order->total,
            'currency' => 'EGP',
            'gateway_order_id' => '8405133',
            'paid_at' => now(),
        ]);

        $token = $user->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders/'.$order->order_number.'/cancel')
            ->assertStatus(422);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid->value, $order->status);
        $this->assertNull($order->cancelled_at);
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Completed->value)->count());
    }

    public function test_shipped_and_delivered_cannot_customer_cancel(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        foreach ([OrderStatus::Shipped->value, OrderStatus::Delivered->value, OrderStatus::Processing->value] as $status) {
            $order = $this->makeOrder($user->id, $status, paid: true);
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/v1/orders/'.$order->order_number.'/cancel')
                ->assertStatus(422);
            $this->assertNull($order->fresh()->cancelled_at);
        }
    }

    public function test_already_cancelled_is_idempotent(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user->id, OrderStatus::Cancelled->value);
        $order->update(['cancelled_at' => now()->subMinute()]);
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders/'.$order->order_number.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(1, Order::query()->whereKey($order->id)->count());
    }

    public function test_cancel_does_not_create_bosta_or_refund(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $order = $this->makeOrder($user->id, OrderStatus::Pending->value);
        $token = $user->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders/'.$order->order_number.'/cancel')
            ->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, Shipment::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
    }

    public function test_payment_race_blocks_cancel_when_paid_at_set_under_lock(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user->id, OrderStatus::AwaitingPayment->value);
        // Simulate payment completion before cancel request handling completes:
        // paid_at set while still awaiting_payment label would be inconsistent;
        // eligibility treats paid_at as paid.
        $order->update(['paid_at' => now()]);

        $token = $user->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders/'.$order->order_number.'/cancel')
            ->assertStatus(422);

        $this->assertNull($order->fresh()->cancelled_at);
    }

    private function makeOrder(int $userId, string $status, bool $paid = false): Order
    {
        return Order::query()->create([
            'user_id' => $userId,
            'is_guest' => false,
            'status' => $status,
            'subtotal' => 100,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 100,
            'currency' => 'EGP',
            'billing_address' => [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email' => 't@example.com',
                'phone' => '01000000000',
            ],
            'shipping_address' => [],
            'paid_at' => $paid ? now() : null,
        ]);
    }
}
