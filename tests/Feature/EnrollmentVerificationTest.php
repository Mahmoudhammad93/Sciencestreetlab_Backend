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
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\OrderItem;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Payment;
use App\Modules\Commerce\Mail\OrderConfirmationMail;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class EnrollmentVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_payment_email_qr_and_public_verification(): void
    {
        Mail::fake();

        $user = User::factory()->create(['name' => 'Mahmoud Hammad', 'email' => 'student@example.com', 'phone' => '01000000000']);
        $course = $this->course('Physics Grade 10');
        $product = $this->product($course, 'PHYS-10');
        $order = $this->pay($user, [$product]);
        $enrollment = Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->firstOrFail();

        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $enrollment->enrollment_verification_token);
        $this->assertNotSame((string) $enrollment->id, $enrollment->enrollment_verification_token);

        $sent = Mail::sent(OrderConfirmationMail::class);
        $this->assertCount(1, $sent, 'Confirmation mail was not sent. sent_at='.(string) $order->fresh()->confirmation_email_sent_at);
        /** @var OrderConfirmationMail $mail */
        $mail = $sent->first();
        $qr = $mail->enrollmentQrs[0] ?? null;
        $this->assertIsArray($qr);
        $html = $mail->render();
        $this->assertSame($enrollment->verificationUrl(), $qr['verification_url']);
        $this->assertStringContainsString('/verify/enrollment/'.$enrollment->enrollment_verification_token, $qr['verification_url']);
        $this->assertStringNotContainsString('/enrollments/'.$enrollment->id, $qr['verification_url']);
        $this->assertStringStartsWith("\x89PNG", $qr['qr_png'], 'QR bytes start with '.bin2hex(substr($qr['qr_png'], 0, 8)));
        $this->assertStringContainsString('Scan this QR code to verify your course enrollment.', $html);
        $this->assertStringContainsString($enrollment->enrollment_verification_token, $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);

        $this->getJson('/api/v1/enrollment-verification/'.$enrollment->enrollment_verification_token)
            ->assertOk()
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.student_name', 'Mahmoud Hammad')
            ->assertJsonPath('data.course_name', 'Physics Grade 10')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.enrollment_id')
            ->assertJsonMissingPath('data.order_id');

        $this->assertSame($order->id, $enrollment->orderItem->order_id);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/me/enrollments')
            ->assertOk()
            ->assertJsonMissing(['enrollment_verification_token' => $enrollment->enrollment_verification_token]);
    }

    public function test_invalid_cancelled_and_refunded_enrollments_are_not_verified(): void
    {
        $user = User::factory()->create(['name' => 'Nour Ali']);
        $course = $this->course('Chemistry');
        $product = $this->product($course, 'CHEM-1');
        Mail::fake();
        $this->pay($user, [$product]);
        $enrollment = Enrollment::query()->firstOrFail();
        $token = $enrollment->enrollment_verification_token;

        $this->getJson('/api/v1/enrollment-verification/not-a-token')
            ->assertNotFound()
            ->assertJsonPath('data.verified', false);

        $this->getJson('/api/v1/enrollment-verification/'.str_repeat('ab', 32))
            ->assertNotFound()
            ->assertJsonPath('data.verified', false);

        $enrollment->update(['status' => EnrollmentStatus::Cancelled]);
        $this->getJson('/api/v1/enrollment-verification/'.$token)
            ->assertNotFound()
            ->assertJsonPath('data.verified', false)
            ->assertJsonMissingPath('data.student_name');

        $enrollment->update(['status' => EnrollmentStatus::Active]);
        $enrollment->orderItem->order->update(['status' => OrderStatus::Refunded->value]);
        $this->getJson('/api/v1/enrollment-verification/'.$token)
            ->assertNotFound()
            ->assertJsonPath('data.verified', false);

        $enrollment->orderItem->order->update(['status' => OrderStatus::Paid->value]);
        $enrollment->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/enrollment-verification/'.$token)
            ->assertNotFound()
            ->assertJsonPath('data.verified', false);
    }

    public function test_multiple_course_products_generate_one_qr_each(): void
    {
        Mail::fake();

        $user = User::factory()->create(['name' => 'Sara']);
        $physics = $this->course('Physics');
        $biology = $this->course('Biology');
        $order = $this->pay($user, [
            $this->product($physics, 'PHYS-QR'),
            $this->product($biology, 'BIO-QR'),
        ]);

        $this->assertSame(2, Enrollment::query()->where('user_id', $user->id)->count());
        $this->assertSame($order->id, Order::query()->firstOrFail()->id);

        Mail::assertSent(OrderConfirmationMail::class, function (OrderConfirmationMail $mail): bool {
            $names = array_column($mail->enrollmentQrs, 'course_name');
            $urls = array_column($mail->enrollmentQrs, 'verification_url');

            return count($mail->enrollmentQrs) === 2
                && $names === ['Physics', 'Biology']
                && count(array_unique($urls)) === 2
                && str_contains($mail->render(), 'Physics')
                && str_contains($mail->render(), 'Biology');
        });
    }

    private function course(string $title): Course
    {
        return Course::query()->create([
            'slug' => str($title)->slug().'-'.uniqid(),
            'access_type' => AccessType::Paid,
            'is_published' => true,
            'published_at' => now(),
            'title' => ['en' => $title, 'ar' => $title],
            'short_description' => ['en' => 's', 'ar' => 'م'],
            'description' => ['en' => 'd', 'ar' => 'و'],
        ]);
    }

    private function product(Course $course, string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'slug' => strtolower($sku).'-'.uniqid(),
            'type' => ProductType::Course,
            'status' => ProductStatus::Published,
            'price' => 100,
            'currency' => 'EGP',
            'course_id' => $course->id,
            'published_at' => now(),
            'name' => ['en' => $course->getTranslation('title', 'en'), 'ar' => $course->getTranslation('title', 'ar')],
        ]);
    }

    /**
     * @param  list<Product>  $products
     */
    private function pay(User $user, array $products): Order
    {
        $order = Order::query()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::AwaitingPayment->value,
            'subtotal' => 100 * count($products),
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'total' => 100 * count($products),
            'currency' => 'EGP',
            'billing_address' => ['first_name' => $user->name, 'email' => $user->email],
            'shipping_address' => ['city' => 'Cairo'],
        ]);

        foreach ($products as $product) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->getTranslation('name', 'en'),
                'product_sku' => $product->sku,
                'quantity' => 1,
                'unit_price' => 100,
                'total_price' => 100,
                'metadata' => ['course_id' => $product->course_id],
            ]);
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => 'mock',
            'amount' => $order->total,
            'currency' => 'EGP',
            'status' => PaymentStatus::Pending->value,
        ]);

        app(PaymentCompletionService::class)->complete($payment);

        return $order->fresh(['items']);
    }
}
