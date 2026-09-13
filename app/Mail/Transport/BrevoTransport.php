<?php

declare(strict_types=1);

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

final class BrevoTransport extends AbstractTransport
{
    public function __construct(private readonly string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->apiKey === '') {
            throw new TransportException('Brevo API key is not configured.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();

        $payload = array_filter([
            'sender' => $this->address($envelope->getSender()),
            'to' => $this->addresses($this->recipients($email, $envelope)),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'replyTo' => $this->address($email->getReplyTo()[0] ?? null),
            'subject' => $email->getSubject() ?? '',
            'htmlContent' => $this->body($email->getHtmlBody()),
            'textContent' => $this->body($email->getTextBody()),
            'attachment' => $this->attachments($email),
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        $response = Http::withHeaders([
            'api-key' => $this->apiKey,
            'accept' => 'application/json',
        ])->acceptJson()->timeout(20)->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->failed()) {
            $reason = $response->json('message') ?: $response->body();

            throw new TransportException(
                'Brevo rejected the message (HTTP '.$response->status().'): '.$reason,
                $response->status(),
            );
        }

        $messageId = $response->json('messageId');

        if (is_string($messageId) && $messageId !== '') {
            $email->getHeaders()->addHeader('X-Brevo-Message-Id', $messageId);
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }

    /**
     * @return list<Address>
     */
    private function recipients(Email $email, Envelope $envelope): array
    {
        return array_values(array_filter(
            $envelope->getRecipients(),
            fn (Address $address): bool => ! in_array($address, array_merge($email->getCc(), $email->getBcc()), true),
        ));
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<array{email: string, name?: string}>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map($this->address(...), $addresses));
    }

    /**
     * @return array{email: string, name?: string}|null
     */
    private function address(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $payload = ['email' => $address->getAddress()];

        if ($address->getName() !== '') {
            $payload['name'] = $address->getName();
        }

        return $payload;
    }

    private function body(mixed $body): ?string
    {
        if (! is_string($body) || $body === '') {
            return null;
        }

        return $body;
    }

    /**
     * @return list<array{content: string, name: string, contentId?: string}>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename') ?: 'attachment.bin';
            $item = [
                'content' => base64_encode($attachment->getBody()),
                'name' => $filename,
            ];

            if ($attachment->hasContentId()) {
                $item['contentId'] = trim($attachment->getContentId(), '<>');
            }

            $attachments[] = $item;
        }

        return $attachments;
    }
}
