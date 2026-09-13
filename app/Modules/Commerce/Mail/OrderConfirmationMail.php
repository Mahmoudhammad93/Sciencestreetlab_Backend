<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Mail;

use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class OrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{course_name: string, verification_url: string, qr_png: string, filename: string}>  $enrollmentQrs
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $enrollmentQrs = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order confirmed — '.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.orders.confirmation',
            with: [
                'order' => $this->order,
                'customerName' => $this->order->user?->name ?? 'there',
                'viewOrderUrl' => $this->viewOrderUrl(),
                'paymentStatus' => $this->order->payment?->status ?? $this->order->status,
                'enrollmentQrs' => $this->enrollmentQrs,
            ],
        );
    }

    public function viewOrderUrl(): string
    {
        $frontend = rtrim((string) config('sciencestreet.frontend_url'), '/');
        $template = config('sciencestreet.order_url_template');

        if (is_string($template) && $template !== '') {
            return str_replace(
                ['{frontend}', '{order_number}', '{order_id}'],
                [$frontend, $this->order->order_number, (string) $this->order->id],
                $template,
            );
        }

        return $frontend.'/orders/'.$this->order->order_number;
    }
}
