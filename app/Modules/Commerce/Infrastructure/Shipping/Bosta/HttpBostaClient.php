<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Shipping\Bosta;

use App\Modules\Commerce\Application\Support\BostaPackageDetailsBuilder;
use App\Modules\Commerce\Application\Support\OrderPaymentMethod;
use App\Modules\Commerce\Domain\Contracts\BostaClientInterface;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Official Bosta HTTP client.
 *
 * Create delivery: POST {BOSTA_API_URL}/api/v2/deliveries?apiVersion=1
 * Auth: Authorization: <raw API key> (no Bearer prefix).
 *
 * @see https://docs.bosta.co/docs/how-to/create-your-first-delivery/
 * @see https://docs.bosta.co/docs/how-to/get-your-api-key/
 */
final class HttpBostaClient implements BostaClientInterface
{
    public function __construct(
        private readonly BostaPackageDetailsBuilder $packageDetails = new BostaPackageDetailsBuilder,
    ) {}

    public function createShipment(Order $order): array
    {
        $this->assertReady();

        $payload = $this->buildCreateDeliveryPayload($order);
        $url = $this->baseUrl().'/api/v2/deliveries?apiVersion=1';

        try {
            $response = Http::withHeaders($this->authHeaders())
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('bosta.http_timeout_seconds', 20))
                ->retry(
                    (int) config('bosta.http_retries', 2),
                    (int) config('bosta.http_retry_sleep_ms', 250),
                    function ($exception): bool {
                        return $exception instanceof ConnectionException;
                    },
                    throw: false,
                )
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Bosta create delivery connection failure', [
                'order_id' => $order->id,
                'business_reference' => $payload['businessReference'] ?? null,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Bosta create delivery timed out or could not connect.', 0, $e);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : null;

        if (! $response->successful()) {
            Log::warning('Bosta create delivery non-2xx', [
                'order_id' => $order->id,
                'business_reference' => $payload['businessReference'] ?? null,
                'http_status' => $response->status(),
                'success' => $json['success'] ?? null,
                'message' => $json['message'] ?? null,
                'error_code' => $json['errorCode'] ?? null,
            ]);

            throw new RuntimeException(
                'Bosta create delivery failed with HTTP '.$response->status()
                .': '.(is_string($json['message'] ?? null) ? $json['message'] : 'unknown error')
            );
        }

        if (! is_array($json) || ($json['success'] ?? null) === false) {
            Log::warning('Bosta create delivery success=false', [
                'order_id' => $order->id,
                'business_reference' => $payload['businessReference'] ?? null,
                'http_status' => $response->status(),
                'message' => is_array($json) ? ($json['message'] ?? null) : null,
                'error_code' => is_array($json) ? ($json['errorCode'] ?? null) : null,
            ]);

            throw new RuntimeException(
                'Bosta create delivery rejected: '
                .(is_array($json) && is_string($json['message'] ?? null) ? $json['message'] : 'success=false')
            );
        }

        $data = $json['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('Bosta create delivery response missing data.');
        }

        $externalId = (string) ($data['_id'] ?? $data['id'] ?? '');
        if ($externalId === '') {
            throw new RuntimeException('Bosta create delivery response missing delivery _id.');
        }

        $tracking = $data['trackingNumber'] ?? null;
        $providerStatus = $this->normalizeProviderStatus($data['state'] ?? null);

        return [
            'external_shipment_id' => $externalId,
            'tracking_number' => $tracking !== null && $tracking !== '' ? (string) $tracking : null,
            'tracking_url' => null,
            'provider_status' => $providerStatus,
            'raw' => $this->sanitizeRaw($data),
            'request' => [
                'url' => $url,
                'type' => $payload['type'] ?? null,
                'businessReference' => $payload['businessReference'] ?? null,
                'cod' => $payload['cod'] ?? null,
                'itemsCount' => $payload['specs']['packageDetails']['itemsCount'] ?? null,
            ],
        ];
    }

    /**
     * Authoritative delivery details via POST /api/v2/deliveries/search.
     *
     * GET /api/v2/deliveries/{id} is not available on the Bosta host we use.
     * Search by trackingNumbers (preferred) or businessReference; match _id when possible.
     *
     * @return array{
     *     external_shipment_id: string,
     *     tracking_number: ?string,
     *     provider_status: ?string,
     *     type: string,
     *     raw: array<string, mixed>
     * }
     */
    public function getDelivery(
        string $externalShipmentId,
        ?string $trackingNumber = null,
        ?string $businessReference = null,
    ): array {
        $this->assertReady(requireContract: false);

        $externalShipmentId = trim($externalShipmentId);
        if ($externalShipmentId === '') {
            throw new RuntimeException('Bosta delivery id is required.');
        }

        $trackingNumber = $trackingNumber !== null ? trim($trackingNumber) : '';
        $businessReference = $businessReference !== null ? trim($businessReference) : '';

        $data = null;
        if ($trackingNumber !== '') {
            $data = $this->searchDelivery(['trackingNumbers' => [$trackingNumber]], $externalShipmentId);
        }
        if ($data === null && $businessReference !== '') {
            $data = $this->searchDelivery(['businessReference' => $businessReference], $externalShipmentId);
        }
        if ($data === null) {
            throw new RuntimeException(
                'Bosta delivery search requires tracking number or business reference '
                .'(GET /deliveries/{id} is unavailable).'
            );
        }

        $id = (string) ($data['_id'] ?? $data['id'] ?? $externalShipmentId);
        $tracking = $data['trackingNumber'] ?? $data['tracking_number'] ?? null;
        if (is_array($tracking)) {
            $tracking = $tracking['number'] ?? $tracking['value'] ?? null;
        }
        $providerStatus = $this->normalizeProviderStatus($data['state'] ?? $data['status'] ?? null);
        $type = $this->normalizeDeliveryType($data['type'] ?? 'SEND');

        return [
            'external_shipment_id' => $id !== '' ? $id : $externalShipmentId,
            'tracking_number' => is_scalar($tracking) && (string) $tracking !== '' ? (string) $tracking : null,
            'provider_status' => $providerStatus,
            'type' => $type,
            'raw' => $this->sanitizeRaw($data),
        ];
    }

    private function normalizeDeliveryType(mixed $type): string
    {
        if (is_array($type)) {
            $type = $type['code'] ?? $type['value'] ?? $type['name'] ?? 'SEND';
        }

        if (is_numeric($type)) {
            // Official create uses type 10 for SEND/Deliver.
            return ((int) $type === 10) ? 'SEND' : (string) (int) $type;
        }

        if (is_scalar($type) && trim((string) $type) !== '') {
            return strtoupper(trim((string) $type));
        }

        return 'SEND';
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function searchDelivery(array $body, string $externalShipmentId): ?array
    {
        $url = $this->baseUrl().'/api/v2/deliveries/search';

        try {
            $response = Http::withHeaders($this->authHeaders())
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('bosta.http_timeout_seconds', 20))
                ->retry(
                    (int) config('bosta.http_retries', 2),
                    (int) config('bosta.http_retry_sleep_ms', 250),
                    function ($exception): bool {
                        return $exception instanceof ConnectionException;
                    },
                    throw: false,
                )
                ->post($url, $body);
        } catch (ConnectionException $e) {
            Log::warning('Bosta delivery search connection failure', [
                'external_shipment_id' => $externalShipmentId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Bosta get delivery timed out or could not connect.', 0, $e);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : null;

        if (! $response->successful()) {
            Log::warning('Bosta delivery search non-2xx', [
                'external_shipment_id' => $externalShipmentId,
                'http_status' => $response->status(),
                'message' => is_array($json) ? ($json['message'] ?? null) : null,
            ]);

            throw new RuntimeException('Bosta get delivery failed with HTTP '.$response->status());
        }

        if (! is_array($json) || ($json['success'] ?? null) === false) {
            throw new RuntimeException(
                'Bosta delivery search rejected: '
                .(is_array($json) && is_string($json['message'] ?? null) ? $json['message'] : 'success=false')
            );
        }

        $deliveries = $json['data']['deliveries'] ?? null;
        if (! is_array($deliveries) || $deliveries === []) {
            return null;
        }

        foreach ($deliveries as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['_id'] ?? $row['id'] ?? '');
            if ($id !== '' && $id === $externalShipmentId) {
                return $row;
            }
        }

        // trackingNumbers / businessReference filters return a single match.
        $first = $deliveries[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * Bosta returns state as either a code string/int or {code, value}.
     */
    private function normalizeProviderStatus(mixed $state): string
    {
        if (is_array($state)) {
            $code = $state['code'] ?? $state['value'] ?? null;
            if ($code !== null && $code !== '') {
                return (string) $code;
            }

            return '10';
        }

        if ($state !== null && $state !== '') {
            return (string) $state;
        }

        return '10';
    }

    /**
     * Official districts for a Bosta city (districtId + names).
     *
     * @return list<array<string, mixed>>
     */
    public function listDistrictsForCity(string $cityId): array
    {
        $this->assertReady(requireContract: false);

        $cityId = trim($cityId);
        if ($cityId === '') {
            return [];
        }

        $response = Http::withHeaders($this->authHeaders())
            ->acceptJson()
            ->timeout((int) config('bosta.http_timeout_seconds', 20))
            ->get($this->baseUrl().'/api/v2/cities/'.$cityId.'/districts');

        if (! $response->successful()) {
            throw new RuntimeException('Bosta districts request failed with HTTP '.$response->status());
        }

        $json = $response->json();
        if (! is_array($json) || ($json['success'] ?? null) === false) {
            throw new RuntimeException('Bosta districts request unsuccessful.');
        }

        $list = $json['data'] ?? [];
        if (! is_array($list)) {
            return [];
        }

        /** @var list<array<string, mixed>> $districts */
        $districts = array_values(array_filter($list, 'is_array'));

        return $districts;
    }

    /**
     * Documented read endpoint used for auth checks and city resolution.
     *
     * @return list<array<string, mixed>>
     */
    public function listCities(): array
    {
        $this->assertReady(requireContract: false);

        $response = Http::withHeaders($this->authHeaders())
            ->acceptJson()
            ->timeout((int) config('bosta.http_timeout_seconds', 20))
            ->get($this->baseUrl().'/api/v2/cities');

        if (! $response->successful()) {
            throw new RuntimeException('Bosta cities request failed with HTTP '.$response->status());
        }

        $json = $response->json();
        if (! is_array($json) || ($json['success'] ?? null) === false) {
            throw new RuntimeException('Bosta cities request unsuccessful.');
        }

        $list = $json['data']['list'] ?? $json['data'] ?? [];
        if (! is_array($list)) {
            return [];
        }

        /** @var list<array<string, mixed>> $cities */
        $cities = array_values(array_filter($list, 'is_array'));

        return $cities;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCreateDeliveryPayload(Order $order): array
    {
        $order->loadMissing(['items', 'user', 'payment']);

        $shipping = is_array($order->shipping_address) ? $order->shipping_address : [];
        $billing = is_array($order->billing_address) ? $order->billing_address : [];

        $firstName = trim((string) ($shipping['first_name'] ?? $billing['first_name'] ?? ''));
        $lastName = trim((string) ($shipping['last_name'] ?? $billing['last_name'] ?? ''));
        $phone = trim((string) ($shipping['phone'] ?? $billing['phone'] ?? $order->user?->phone ?? ''));
        $email = trim((string) ($shipping['email'] ?? $billing['email'] ?? $order->user?->email ?? ''));

        if ($firstName === '' || $phone === '') {
            throw new RuntimeException(
                'Bosta delivery requires receiver firstName and phone from the order shipping/billing address.'
            );
        }

        if ($lastName === '') {
            $lastName = '-';
        }

        $dropOff = $this->buildDropOffAddress($shipping, $billing);
        $packageDetails = $this->packageDetails->build($order);

        // ONLINE prepaid (Fawaterak): collectible COD must be 0.
        // Cash on Delivery: Bosta collects the authoritative final order total.
        // Do NOT use Bosta escrowInfo prepaid feature for external Fawaterak payments.
        $cod = $this->collectibleCodAmount($order);

        $receiver = array_filter([
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $payload = [
            'type' => 10,
            'cod' => $cod,
            'businessReference' => (string) $order->order_number,
            'receiver' => $receiver,
            'dropOffAddress' => $dropOff,
            'specs' => [
                'packageDetails' => [
                    // Official Bosta contract: description + itemsCount only.
                    // Product names/qty come from order-item snapshots (not live Product).
                    'description' => $packageDetails['description'],
                    'itemsCount' => $packageDetails['itemsCount'],
                ],
            ],
            'notes' => 'Order '.$order->order_number,
        ];

        $webhookUrl = trim((string) config('bosta.webhook_url', ''));
        if ($webhookUrl !== '') {
            $payload['webhookUrl'] = $webhookUrl;
            $secret = (string) config('bosta.webhook_secret', '');
            $headerName = (string) config('bosta.webhook_auth_header', 'Authorization');
            if ($secret !== '' && $headerName !== '') {
                $payload['webhookCustomHeaders'] = [
                    $headerName => $secret,
                ];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $shipping
     * @param  array<string, mixed>  $billing
     * @return array<string, mixed>
     */
    private function buildDropOffAddress(array $shipping, array $billing): array
    {
        $firstLine = trim((string) (
            $shipping['address']
            ?? $shipping['first_line']
            ?? $shipping['firstLine']
            ?? $billing['address']
            ?? ''
        ));

        if ($firstLine === '') {
            throw new RuntimeException(
                'Bosta delivery requires a street address (dropOffAddress.firstLine). '
                .'Checkout shipping address is missing an address line.'
            );
        }

        $cityId = trim((string) (
            $shipping['bosta_city_id']
            ?? $shipping['city_id']
            ?? $shipping['cityId']
            ?? ''
        ));
        $cityName = trim((string) ($shipping['city'] ?? $billing['city'] ?? ''));

        $zoneId = trim((string) (
            $shipping['bosta_zone_id']
            ?? $shipping['zone_id']
            ?? $shipping['zoneId']
            ?? ''
        ));
        $districtId = trim((string) (
            $shipping['bosta_district_id']
            ?? $shipping['district_id']
            ?? $shipping['districtId']
            ?? ''
        ));
        $districtName = trim((string) (
            $shipping['district_name']
            ?? $shipping['districtName']
            ?? $shipping['district']
            ?? $shipping['area']
            ?? $billing['district_name']
            ?? $billing['districtName']
            ?? $billing['district']
            ?? $billing['area']
            ?? ''
        ));

        if ($cityId === '' && $cityName !== '') {
            $cityId = $this->resolveCityIdByName($cityName) ?? '';
        }

        if ($cityId === '' && $zoneId === '' && $districtId === '') {
            throw new RuntimeException(
                'Bosta delivery requires city, zoneId, or districtId (error 3009). '
                .'Could not resolve a Bosta city identifier from shipping city'
                .($cityName !== '' ? ' "'.$cityName.'"' : '')
                .'. Store bosta_city_id / zone_id / district_id on the shipping address, '
                .'or use a city name that matches Bosta GET /api/v2/cities.'
            );
        }

        if ($districtId === '' && $districtName === '') {
            throw new RuntimeException(
                'Bosta delivery requires districtId or districtName on dropOffAddress. '
                .'Checkout shipping address is missing a district/area. '
                .'Ask the customer for the Bosta district that matches their city.'
            );
        }

        $address = array_filter([
            'city' => $cityId !== '' ? $cityId : null,
            'zoneId' => $zoneId !== '' ? $zoneId : null,
            'districtId' => $districtId !== '' ? $districtId : null,
            'districtName' => $districtId === '' && $districtName !== '' ? $districtName : null,
            'firstLine' => $firstLine,
            'secondLine' => trim((string) ($shipping['second_line'] ?? $shipping['secondLine'] ?? '')) ?: null,
            'buildingNumber' => trim((string) ($shipping['building_number'] ?? $shipping['buildingNumber'] ?? '')) ?: null,
            'floor' => trim((string) ($shipping['floor'] ?? '')) ?: null,
            'apartment' => trim((string) ($shipping['apartment'] ?? '')) ?: null,
        ], static fn ($v) => $v !== null && $v !== '');

        return $address;
    }

    private function resolveCityIdByName(string $cityName): ?string
    {
        $needle = mb_strtolower(trim($cityName));
        if ($needle === '') {
            return null;
        }

        foreach ($this->listCities() as $city) {
            $candidates = [
                (string) ($city['name'] ?? ''),
                (string) ($city['nameAr'] ?? ''),
                (string) ($city['alias'] ?? ''),
            ];
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && mb_strtolower(trim($candidate)) === $needle) {
                    $id = (string) ($city['_id'] ?? '');

                    return $id !== '' ? $id : null;
                }
            }
        }

        return null;
    }

    /**
     * Authoritative Bosta collectible amount.
     * COD: final order.total (after discount/shipping/tax). Online prepaid: 0.
     */
    private function collectibleCodAmount(Order $order): float|int
    {
        if (! OrderPaymentMethod::isCashOnDelivery($order)) {
            return 0;
        }

        $amount = round((float) $order->total, 2);

        // Prefer int when whole pounds to match historical prepaid assertions.
        if (fmod($amount, 1.0) === 0.0) {
            return (int) $amount;
        }

        return $amount;
    }

    private function assertReady(bool $requireContract = true): void
    {
        $key = trim((string) config('bosta.api_key', ''));
        if ($key === '') {
            throw new RuntimeException('BOSTA_API_KEY is not configured.');
        }

        if ($requireContract && ! (bool) config('bosta.api_contract_ready')) {
            throw new RuntimeException(
                'BLOCKED_BY_BOSTA_CREDENTIALS_OR_DOCS: set BOSTA_API_CONTRACT_READY=true after verifying official docs.'
            );
        }

        if (trim((string) config('bosta.api_url', '')) === '') {
            throw new RuntimeException('BOSTA_API_URL is not configured.');
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('bosta.api_url'), '/');
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => (string) config('bosta.api_key'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeRaw(array $data): array
    {
        unset(
            $data['apiKey'],
            $data['api_key'],
            $data['authorization'],
            $data['Authorization'],
            $data['secret'],
        );

        return $data;
    }
}
