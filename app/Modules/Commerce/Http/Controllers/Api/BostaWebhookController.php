<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Modules\Commerce\Application\Services\BostaWebhookService;
use App\Modules\Commerce\Application\Support\BostaWebhookResult;
use App\Modules\Commerce\Domain\Contracts\BostaWebhookVerifierInterface;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\ConfiguredBostaWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Public Bosta shipment-status webhook (no Sanctum / CSRF).
 *
 * Auth: configurable custom header + BOSTA_WEBHOOK_SECRET via verifier.
 */
final class BostaWebhookController
{
    public function __construct(
        private readonly BostaWebhookVerifierInterface $verifier,
        private readonly BostaWebhookService $webhooks,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! (bool) config('bosta.enabled')) {
            return response()->json(['message' => 'Bosta shipping is disabled.'], 404);
        }

        $diag = $this->diagnose($request);

        Log::info('WEBHOOK_RECEIVED', [
            'provider' => 'bosta',
            'method' => $request->method(),
            'path' => '/api/v1/webhooks/bosta',
            'content_type' => $request->header('Content-Type'),
            'user_agent' => $request->userAgent(),
            'header_name_expected' => $diag['header_name_expected'] ?? config('bosta.webhook_auth_header'),
            'header_present' => $diag['header_present'] ?? false,
            'has_bearer_scheme' => $diag['has_bearer_scheme'] ?? false,
        ]);

        if (! ($diag['accepted'] ?? false)) {
            Log::warning('WEBHOOK_AUTH_REJECTED', [
                'provider' => 'bosta',
                'reason' => $diag['reason'] ?? 'invalid_auth_header',
                'blocked' => $diag['reason'] ?? 'invalid_auth_header',
                'header_name_expected' => $diag['header_name_expected'] ?? null,
                'header_present' => $diag['header_present'] ?? false,
                'header_empty' => $diag['header_empty'] ?? true,
                'has_bearer_scheme' => $diag['has_bearer_scheme'] ?? false,
                'length_matches_secret' => $diag['length_matches_secret'] ?? null,
                'auth_ready' => $diag['auth_ready'] ?? null,
                'secret_configured' => $diag['secret_configured'] ?? null,
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        Log::info('WEBHOOK_AUTH_ACCEPTED', [
            'provider' => 'bosta',
            'header_name_expected' => $diag['header_name_expected'] ?? null,
            'has_bearer_scheme' => $diag['has_bearer_scheme'] ?? false,
        ]);

        try {
            $result = $this->webhooks->handle($request->all());

            Log::info('WEBHOOK_PROCESSED', [
                'provider' => 'bosta',
                'outcome' => $result->outcome,
                'duplicate' => $result->duplicate,
                'fulfilled' => $result->fulfilled,
                'external_shipment_id' => $result->externalShipmentId,
                'provider_status' => $result->providerStatus,
                'mapped_status' => $result->mappedStatus?->value,
                'local_shipment_id' => $result->shipment?->id,
                'local_order_id' => $result->shipment?->order_id,
            ]);

            return response()->json($this->payload($result));
        } catch (InvalidArgumentException $e) {
            Log::warning('WEBHOOK_PROCESSED', [
                'provider' => 'bosta',
                'outcome' => 'payload_rejected',
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Bosta webhook processing failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnose(Request $request): array
    {
        if ($this->verifier instanceof ConfiguredBostaWebhookVerifier) {
            return $this->verifier->diagnose($request);
        }

        $accepted = $this->verifier->verify($request);

        return [
            'accepted' => $accepted,
            'auth_ready' => true,
            'secret_configured' => true,
            'header_name_expected' => (string) config('bosta.webhook_auth_header', 'Authorization'),
            'header_present' => $accepted,
            'header_empty' => ! $accepted,
            'has_bearer_scheme' => false,
            'length_matches_secret' => null,
            'reason' => $accepted ? 'accepted' : 'invalid_auth_header',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(BostaWebhookResult $result): array
    {
        $body = [
            'ok' => true,
            'outcome' => $result->outcome,
            'duplicate' => $result->duplicate,
            'external_shipment_id' => $result->externalShipmentId,
        ];

        if ($result->shipment !== null) {
            $body['shipment_id'] = $result->shipment->id;
            $body['order_id'] = $result->shipment->order_id;
            $body['status'] = $result->shipment->status->value;
            $body['fulfilled'] = $result->fulfilled;
        }

        if ($result->mappedStatus !== null) {
            $body['mapped_status'] = $result->mappedStatus->value;
        }

        return $body;
    }
}
