<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BrevoMailTransportTest extends TestCase
{
    public function test_brevo_mailer_posts_transactional_payload_without_exposing_the_key_in_the_body(): void
    {
        config([
            'services.brevo.key' => 'xkeysib-test-key',
            'mail.default' => 'brevo',
            'mail.from.address' => 'noreply@sciencestreetlab.com',
            'mail.from.name' => 'Science Street Lab',
        ]);

        Http::fake([
            'api.brevo.com/*' => Http::response(['messageId' => '<abc@brevo.com>'], 201),
        ]);

        Mail::raw('Order confirmed', function ($message): void {
            $message->to('student@example.com', 'Student')
                ->subject('Order confirmed')
                ->from('noreply@sciencestreetlab.com', 'Science Street Lab');
        });

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'xkeysib-test-key')
                && ($body['to'][0]['email'] ?? null) === 'student@example.com'
                && ($body['sender']['email'] ?? null) === 'noreply@sciencestreetlab.com'
                && ($body['subject'] ?? null) === 'Order confirmed'
                && ! str_contains(json_encode($body), 'xkeysib-test-key');
        });
    }
}
