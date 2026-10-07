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

final class OrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{course_name: string, verification_url: string, qr_png: string, filename: string}>  $enrollmentQrs
     * @param  string|null  $rawStatusToken  Transient guest status capability for email URL only; never hashed-invertible.
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $enrollmentQrs = [],
        public readonly ?string $rawStatusToken = null,
        public readonly ?string $mailLocale = null,
        public readonly ?string $accountActivationUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->withMailLocale(fn (): string => __('mail.order_confirmation_subject', [
                'order' => $this->order->order_number,
            ])),
        );
    }

    public function content(): Content
    {
        $billing = is_array($this->order->billing_address) ? $this->order->billing_address : [];
        $guestName = trim(((string) ($billing['first_name'] ?? '')).' '.((string) ($billing['last_name'] ?? '')));

        return new Content(
            markdown: 'mail.orders.confirmation',
            with: [
                'order' => $this->order,
                'customerName' => $this->order->user?->name
                    ?: ($guestName !== '' ? $guestName : 'there'),
                'viewOrderUrl' => $this->viewOrderUrl(),
                'paymentStatus' => $this->order->payment?->status ?? $this->order->status,
                'viewOrderLabel' => $this->withMailLocale(fn (): string => __('mail.view_order')),
                'accountActivationUrl' => $this->accountActivationUrl,
                'accountActivationLabel' => $this->withMailLocale(fn (): string => __('mail.create_account_to_track_order')),
            ],
        );
    }

    public function viewOrderUrl(): string
    {
        $frontend = rtrim((string) config('sciencestreet.frontend_url'), '/');
        $template = config('sciencestreet.order_url_template');

        if ($this->order->is_guest && $this->order->user_id === null) {
            $url = $frontend.'/order-status/'.rawurlencode((string) $this->order->order_number);
            if (is_string($this->rawStatusToken) && $this->rawStatusToken !== '') {
                $url .= '?token='.rawurlencode($this->rawStatusToken);
            }

            return $url;
        }

        if (is_string($template) && $template !== '') {
            return str_replace(
                ['{frontend}', '{order_number}', '{order_id}'],
                [$frontend, $this->order->order_number, (string) $this->order->id],
                $template,
            );
        }

        return $frontend.'/account/orders/'.$this->order->order_number;
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

        $userLocale = $this->order->user?->locale;
        if (is_string($userLocale) && in_array($userLocale, ['ar', 'en'], true)) {
            return $userLocale;
        }

        $default = (string) config('sciencestreet.default_locale', 'ar');

        return in_array($default, ['ar', 'en'], true) ? $default : 'ar';
    }
}
