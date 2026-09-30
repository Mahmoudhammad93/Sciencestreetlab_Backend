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

final class GuestCourseClaimMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly string $rawToken,
        public readonly ?string $mailLocale = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->withMailLocale(fn (): string => __('mail.guest_claim_subject', [
                'order' => $this->order->order_number,
            ])),
        );
    }

    public function content(): Content
    {
        $frontend = rtrim((string) config('sciencestreet.frontend_url'), '/');

        return new Content(
            markdown: 'mail.orders.guest-claim',
            with: [
                'order' => $this->order,
                'claimUrl' => $frontend.'/claim/'.$this->rawToken,
                'customerName' => $this->order->billing_address['first_name'] ?? 'there',
                'activateLabel' => $this->withMailLocale(fn (): string => __('mail.activate_course')),
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
