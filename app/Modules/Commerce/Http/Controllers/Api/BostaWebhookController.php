<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Modules\Commerce\Application\Services\BostaWebhookService;
use App\Modules\Commerce\Domain\Contracts\BostaWebhookVerifierInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

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
            $shipment = $this->webhooks->handle($request->all());

            return response()->json([
                'ok' => true,
                'shipment_id' => $shipment->id,
                'status' => $shipment->status->value,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Bosta webhook processing failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Webhook processing failed'], 500);
        }
    }
}
