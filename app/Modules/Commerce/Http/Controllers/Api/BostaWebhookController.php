<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Modules\Commerce\Application\Services\BostaWebhookService;
use App\Modules\Commerce\Application\Support\BostaWebhookResult;
use App\Modules\Commerce\Domain\Contracts\BostaWebhookVerifierInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Public Bosta shipment-status webhook (no Sanctum / CSRF).
 *
 * Auth is verifier-based. Production signature algorithm remains
 * BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS until official Bosta docs are applied.
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

        if (! $this->verifier->verify($request)) {
            Log::warning('Rejected Bosta webhook: invalid signature or verifier blocked', [
                'blocked' => ! (bool) config('bosta.webhook_signature_ready')
                    ? 'BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS'
                    : 'invalid_signature',
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $result = $this->webhooks->handle($request->all());

            return response()->json($this->payload($result));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Bosta webhook processing failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Webhook processing failed'], 500);
        }
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
