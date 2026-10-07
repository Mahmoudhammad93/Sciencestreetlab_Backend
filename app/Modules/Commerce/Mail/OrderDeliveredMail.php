<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Mail;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

/**
 * Delivered / course-ready email (EMAIL 2).
 * Distinct from OrderConfirmationMail (order accepted / paid receipt).
 */
final class OrderDeliveredMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $courseNames
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $courseNames,
        public readonly bool $accountExists,
        public readonly ?string $activationUrl = null,
        public readonly ?string $loginUrl = null,
        public readonly ?string $forgotPasswordUrl = null,
        public readonly ?string $mailLocale = null,
        /** @var list<array{course_name: string, verification_url: string, qr_png: string, filename: string}> */
        public readonly array $enrollmentQrs = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->withMailLocale(fn (): string => __('mail.order_delivered_subject', [
                'order' => $this->order->order_number,
            ])),
        );
    }

    public function content(): Content
    {
        $billing = is_array($this->order->billing_address) ? $this->order->billing_address : [];
        $guestName = trim(((string) ($billing['first_name'] ?? '')).' '.((string) ($billing['last_name'] ?? '')));

        return new Content(
            markdown: 'mail.orders.delivered',
            with: [
                'order' => $this->order,
                'customerName' => $this->order->user?->name
                    ?: ($guestName !== '' ? $guestName : 'there'),
                'courseNames' => $this->courseNames,
                'accountExists' => $this->accountExists,
                'activationUrl' => $this->activationUrl,
                'loginUrl' => $this->loginUrl,
                'forgotPasswordUrl' => $this->forgotPasswordUrl,
                'primaryCtaLabel' => $this->withMailLocale(fn (): string => $this->accountExists
                    ? __('mail.login_to_account')
                    : __('mail.create_account_and_access_course')),
                'forgotLabel' => $this->withMailLocale(fn (): string => __('mail.forgot_password')),
            ],
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withMailLocale(callable $callback): mixed
    {
        $locale = $this->resolveLocale();
        $previous = App::getLocale();
        App::setLocale($locale);

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    private function resolveLocale(): string
    {
        if (is_string($this->mailLocale) && in_array($this->mailLocale, ['ar', 'en'], true)) {
            return $this->mailLocale;
        }

        $default = (string) config('sciencestreet.default_locale', 'ar');

        return in_array($default, ['ar', 'en'], true) ? $default : 'ar';
    }
}
