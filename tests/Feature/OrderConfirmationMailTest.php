<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use App\Modules\Commerce\Application\Services\PaymentCompletionService;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\OrderPaid;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Commerce\Mail\OrderConfirmationMail;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class OrderConfirmationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_payment_sends_confirmation_email_with_order_and_items(): void
    {
        Mail::fake();

        $order = $this->paidOrder();

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($order): bool {
            $html = $mail->render();

            return $mail->hasTo($order->user->email)
                && str_contains($html, $order->user->name)
                && str_contains($html, $order->order_number)
                && str_contains($html, 'Lab notebook')
                && str_contains($html, '2')
                && str_contains($html, '50.00')
                && str_contains($html, '90.00')
                && str_contains($html, '10.00')
                && str_contains($html, 'EGP')
                && str_contains($html, rtrim((string) config('sciencestreet.frontend_url'), '/').'/orders/'.$order->order_number);
        });

        $this->assertNotNull($order->fresh()->confirmation_email_sent_at);
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_failed_payment_and_unpaid_order_do_not_send_confirmation_email(): void
    {
        Mail::fake();

        [$user, $product] = $this->buyerAndProduct();
        $order = $this->makeOrder($user, $product, OrderStatus::AwaitingPayment);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'mock',
            'amount' => $order->total,
            'currency' => 'EGP',
            'status' => PaymentStatus::Pending->value,
        ]);

        Mail::assertNothingSent();

        app(PaymentCompletionService::class)->fail($payment);

        Mail::assertNothingSent();
        $this->assertNull($order->fresh()->confirmation_email_sent_at);
    }

    public function test_duplicate_order_paid_event_does_not_send_a_second_email(): void
    {
        Mail::fake();

        $order = $this->paidOrder();

        event(new OrderPaid($order->fresh(['items'])));

        Mail::assertSent(OrderConfirmationMail::class, 1);
    }

    public function test_course_purchase_confirmation_still_enrolls_and_emails_once(): void
    {
        Mail::fake();

        [$user, $product] = $this->buyerAndProduct();
        $course = Course::query()->create([
            'slug' => 'order-course-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => 'Lab course', 'ar' => 'دورة'],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
        $product->update(['course_id' => $course->id, 'type' => ProductType::Course]);

        $order = $this->makeOrder($user, $product->fresh(), OrderStatus::AwaitingPayment);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'mock',
            'amount' => $order->total,
            'currency' => 'EGP',
            'status' => PaymentStatus::Pending->value,
        ]);

        app(PaymentCompletionService::class)->complete($payment);

        Mail::assertSent(OrderConfirmationMail::class, 1);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_normal_product_purchase_confirmation_works(): void
    {
        Mail::fake();

        $order = $this->paidOrder();

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail) use ($order): bool {
            return $mail->order->is($order) && $mail->order->items->contains('product_name', 'Lab notebook');
        });
        $this->assertNull($order->items()->first()?->product?->course_id);
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function buyerAndProduct(): array
    {
        $user = User::factory()->create(['name' => 'Nour Ali']);
        $product = Product::query()->create([
            'sku' => 'NOTE-1',
            'slug' => 'lab-notebook',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 50,
            'currency' => 'EGP',
            'published_at' => now(),
            'name' => ['en' => 'Lab notebook', 'ar' => 'دفتر'],
        ]);

        return [$user, $product];
    }

    private function paidOrder(): Order
    {
        [$user, $product] = $this->buyerAndProduct();
        $order = $this->makeOrder($user, $product, OrderStatus::AwaitingPayment);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'mock',
            'amount' => $order->total,
            'currency' => 'EGP',
            'status' => PaymentStatus::Pending->value,
        ]);

        app(PaymentCompletionService::class)->complete($payment);

        return $order->fresh(['items', 'user', 'payment']);
    }

    private function makeOrder(User $user, Product $product, OrderStatus $status): Order
    {
        $order = Order::query()->create([
            'user_id' => $user->id,
            'status' => $status->value,
            'subtotal' => 100,
            'discount_amount' => 10,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 90,
            'currency' => 'EGP',
            'billing_address' => ['first_name' => $user->name, 'email' => $user->email],
            'shipping_address' => ['city' => 'Cairo'],
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Lab notebook',
            'product_sku' => $product->sku,
            'quantity' => 2,
            'unit_price' => 50,
            'total_price' => 100,
        ]);

        return $order->load('items');
    }
}
