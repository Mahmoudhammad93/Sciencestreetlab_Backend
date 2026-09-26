<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Mail\OrderConfirmationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderConfirmationMailUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_view_order_url_uses_account_orders_path(): void
    {
        config([
            'sciencestreet.frontend_url' => 'https://app.sciencestreetlab.com',
            'sciencestreet.order_url_template' => null,
        ]);

        $user = User::factory()->create();
        $order = Order::query()->create([
            'user_id' => $user->id,
            'status' => 'paid',
            'subtotal' => 10,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 10,
            'currency' => 'EGP',
            'billing_address' => [],
            'shipping_address' => [],
        ]);
        $order->forceFill(['order_number' => 'SS-TESTORDER1'])->save();

        $mail = new OrderConfirmationMail($order->fresh());
        $url = $mail->viewOrderUrl();

        $this->assertSame(
            'https://app.sciencestreetlab.com/account/orders/SS-TESTORDER1',
            $url
        );
        $this->assertDoesNotMatchRegularExpression(
            '#https://app\.sciencestreetlab\.com/orders/SS-TESTORDER1$#',
            $url
        );
    }
}
