<?php

/**
 * READ-ONLY production audit helper for Bosta status sync.
 * Copy into container and run via: php /tmp/bosta_ro_audit.php
 * Does NOT mutate shipments/orders/payments.
 */

declare(strict_types=1);

require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\Commerce\Domain\Contracts\BostaClientInterface;
use App\Modules\Commerce\Domain\Enums\ShipmentProvider;
use App\Modules\Commerce\Domain\Enums\ShipmentStatus;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Shipment;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\BostaStatusMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$email = 'mahmoudhammad3005@gmail.com';

echo "=== CUSTOMER LOOKUP ===\n";
$orders = Order::query()
    ->where(function ($q) use ($email) {
        $q->where('billing_email', $email)
            ->orWhere('email', $email)
            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(billing_address, '$.email')) = ?", [$email])
            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(shipping_address, '$.email')) = ?", [$email]);
    })
    ->orWhereHas('user', fn ($q) => $q->where('email', $email))
    ->with('bostaShipment')
    ->orderByDesc('id')
    ->get();

foreach ($orders as $o) {
    $s = $o->bostaShipment;
    echo json_encode([
        'order_id' => $o->id,
        'order_number' => $o->order_number,
        'order_status' => $o->status,
        'fulfilled_at' => $o->fulfilled_at,
        'user_id' => $o->user_id,
        'is_guest' => (bool) $o->is_guest,
        'shipment_id' => $s?->id,
        'external_id' => $s?->external_shipment_id,
        'tracking' => $s?->tracking_number,
        'local_shipment_status' => $s?->status?->value ?? $s?->status,
        'provider_status' => $s?->provider_status,
        'last_webhook_at' => $s?->last_webhook_at,
        'last_synced_at' => $s?->metadata['last_status_synced_at'] ?? null,
        'last_source' => $s?->metadata['last_status_source'] ?? null,
    ], JSON_UNESCAPED_UNICODE)."\n";
}

$target = $orders->firstWhere('id', 2643) ?? $orders->first();
if ($target === null || $target->bostaShipment === null) {
    echo "NO_TARGET_SHIPMENT\n";
    exit(0);
}

$shipment = $target->bostaShipment;
$externalId = (string) $shipment->external_shipment_id;

echo "\n=== BOSTA GET DELIVERY (read-only) ===\n";
$hasGetDelivery = method_exists(app(BostaClientInterface::class), 'getDelivery');
echo "client_has_getDelivery=".($hasGetDelivery ? 'yes' : 'no')."\n";

$bosta = null;
try {
    if ($hasGetDelivery) {
        $bosta = app(BostaClientInterface::class)->getDelivery($externalId);
    } else {
        $url = rtrim((string) config('bosta.api_url'), '/').'/api/v2/deliveries/'.rawurlencode($externalId);
        $response = Http::withHeaders([
            'Authorization' => (string) config('bosta.api_key'),
            'Accept' => 'application/json',
        ])->timeout(20)->get($url);
        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : (is_array($json) ? $json : []);
        $bosta = [
            'http_status' => $response->status(),
            'provider_status' => isset($data['state']) ? (string) $data['state'] : null,
            'tracking_number' => $data['trackingNumber'] ?? null,
            'type' => $data['type'] ?? null,
            'updated_at' => $data['updatedAt'] ?? $data['stateUpdatedAt'] ?? null,
            'raw_keys' => array_keys($data),
        ];
    }
    echo json_encode($bosta, JSON_UNESCAPED_UNICODE)."\n";
} catch (Throwable $e) {
    echo 'BOSTA_FETCH_ERROR='.$e->getMessage()."\n";
}

$localStatus = $shipment->status instanceof ShipmentStatus ? $shipment->status->value : (string) $shipment->status;
$providerCode = is_array($bosta) ? ($bosta['provider_status'] ?? null) : null;
$mapped = $providerCode !== null
    ? app(BostaStatusMapper::class)->map((string) $providerCode, (string) ($bosta['type'] ?? 'SEND'))->value
    : null;

echo "\nCOMPARE local={$localStatus} bosta_code={$providerCode} mapped={$mapped}\n";
echo 'STATUS_IN_SYNC='.(($mapped !== null && $mapped === $localStatus) ? 'YES' : 'NO')."\n";

echo "\n=== RECENT SAMPLE AUDIT (max 20 active) ===\n";
$sample = Shipment::query()
    ->where('provider', ShipmentProvider::Bosta->value)
    ->whereNotNull('external_shipment_id')
    ->where('external_shipment_id', '!=', '')
    ->where('created_at', '>=', now()->subDays(30))
    ->orderByDesc('id')
    ->limit(20)
    ->get();

$checked = 0;
$inSync = 0;
$out = [];

foreach ($sample as $s) {
    $checked++;
    try {
        if ($hasGetDelivery) {
            $d = app(BostaClientInterface::class)->getDelivery((string) $s->external_shipment_id);
            $code = (string) ($d['provider_status'] ?? '');
            $type = (string) ($d['type'] ?? 'SEND');
        } else {
            $url = rtrim((string) config('bosta.api_url'), '/').'/api/v2/deliveries/'.rawurlencode((string) $s->external_shipment_id);
            $response = Http::withHeaders([
                'Authorization' => (string) config('bosta.api_key'),
                'Accept' => 'application/json',
            ])->timeout(20)->get($url);
            $json = $response->json();
            $data = is_array($json['data'] ?? null) ? $json['data'] : [];
            $code = isset($data['state']) ? (string) $data['state'] : '';
            $type = isset($data['type']) ? (string) $data['type'] : 'SEND';
        }
        $mappedStatus = app(BostaStatusMapper::class)->map($code, $type)->value;
        $local = $s->status instanceof ShipmentStatus ? $s->status->value : (string) $s->status;
        if ($mappedStatus === $local || ($local === 'delivered' && $mappedStatus === 'delivered')) {
            $inSync++;
        } else {
            $out[] = [
                'shipment_id' => $s->id,
                'order_id' => $s->order_id,
                'local' => $local,
                'bosta' => $code,
                'mapped' => $mappedStatus,
                'last_webhook_at' => $s->last_webhook_at,
            ];
        }
        usleep(150000);
    } catch (Throwable $e) {
        $out[] = [
            'shipment_id' => $s->id,
            'order_id' => $s->order_id,
            'error' => $e->getMessage(),
            'last_webhook_at' => $s->last_webhook_at,
        ];
    }
}

echo "SHIPMENTS_CHECKED={$checked}\n";
echo 'IN_SYNC='.$inSync."\n";
echo 'OUT_OF_SYNC='.count($out)."\n";
foreach ($out as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
}

echo "\n=== SCHEDULER / CONFIG (no secrets) ===\n";
echo 'bosta_enabled='.(config('bosta.enabled') ? '1' : '0')."\n";
echo 'use_fake='.(config('bosta.use_fake') ? '1' : '0')."\n";
echo 'webhook_auth_ready='.(config('bosta.webhook_auth_ready') ? '1' : '0')."\n";
echo 'webhook_auth_header='.(string) config('bosta.webhook_auth_header')."\n";
echo 'reconcile_lookback='.(int) config('bosta.reconcile_lookback_days', 0)."\n";
echo 'has_reconcile_command='.(class_exists(\App\Console\Commands\ReconcileBostaShipmentsCommand::class) ? 'yes' : 'no')."\n";
echo 'has_status_service='.(class_exists(\App\Modules\Commerce\Application\Services\BostaShipmentStatusService::class) ? 'yes' : 'no')."\n";

echo "DONE\n";
