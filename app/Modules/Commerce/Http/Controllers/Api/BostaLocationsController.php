<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\HttpBostaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Safe public location options for checkout — proxies official Bosta cities/districts
 * without exposing API credentials to the browser.
 */
final class BostaLocationsController extends Controller
{
    private const CITIES_CACHE_TTL_SECONDS = 3600;

    private const DISTRICTS_CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly HttpBostaClient $bosta,
    ) {}

    public function cities(Request $request): JsonResponse
    {
        if (! (bool) config('bosta.enabled')) {
            return response()->json(['data' => []]);
        }

        try {
            $cities = $this->cachedCities();
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to load shipping cities.',
            ], 503);
        }

        $search = $this->searchTerm($request);
        if ($search !== '') {
            $cities = array_values(array_filter(
                $cities,
                static function (array $city) use ($search): bool {
                    $hay = mb_strtolower(
                        ($city['name'] ?? '').' '.($city['name_ar'] ?? '').' '.($city['code'] ?? '')
                    );

                    return str_contains($hay, $search);
                }
            ));
        }

        return response()->json(['data' => $cities]);
    }

    public function districts(Request $request, string $cityId): JsonResponse
    {
        $cityId = trim($cityId);
        if ($cityId === '' || strlen($cityId) > 64 || ! preg_match('/^[A-Za-z0-9_-]+$/', $cityId)) {
            return response()->json(['message' => 'Invalid city id.'], 422);
        }

        if (! (bool) config('bosta.enabled')) {
            return response()->json(['data' => []]);
        }

        try {
            $districts = $this->cachedDistricts($cityId);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to load shipping districts.',
            ], 503);
        }

        $search = $this->searchTerm($request);
        if ($search !== '') {
            $districts = array_values(array_filter(
                $districts,
                static function (array $row) use ($search): bool {
                    $hay = mb_strtolower(
                        ($row['name'] ?? '').' '
                        .($row['name_ar'] ?? '').' '
                        .($row['zone_name'] ?? '').' '
                        .($row['zone_name_ar'] ?? '')
                    );

                    return str_contains($hay, $search);
                }
            ));
        }

        return response()->json(['data' => $districts]);
    }

    /**
     * @return list<array{id: string, name: string, name_ar: string, code: string}>
     */
    private function cachedCities(): array
    {
        /** @var list<array{id: string, name: string, name_ar: string, code: string}> $mapped */
        $mapped = Cache::remember(
            'bosta.locations.cities.v1',
            self::CITIES_CACHE_TTL_SECONDS,
            function (): array {
                $cities = $this->bosta->listCities();
                $data = [];
                foreach ($cities as $city) {
                    if (! is_array($city)) {
                        continue;
                    }
                    if (($city['dropOffAvailability'] ?? true) === false) {
                        continue;
                    }
                    $id = trim((string) ($city['_id'] ?? ''));
                    $name = trim((string) ($city['name'] ?? ''));
                    if ($id === '' || $name === '') {
                        continue;
                    }
                    $data[] = [
                        'id' => $id,
                        'name' => $name,
                        'name_ar' => trim((string) ($city['nameAr'] ?? $city['alias'] ?? '')),
                        'code' => trim((string) ($city['code'] ?? '')),
                    ];
                }
                usort($data, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

                return $data;
            }
        );

        return $mapped;
    }

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     name_ar: string,
     *     zone_id: ?string,
     *     zone_name: ?string,
     *     zone_name_ar: ?string
     * }>
     */
    private function cachedDistricts(string $cityId): array
    {
        /** @var list<array{
         *     id: string,
         *     name: string,
         *     name_ar: string,
         *     zone_id: ?string,
         *     zone_name: ?string,
         *     zone_name_ar: ?string
         * }> $mapped
         */
        $mapped = Cache::remember(
            'bosta.locations.districts.v1.'.$cityId,
            self::DISTRICTS_CACHE_TTL_SECONDS,
            function () use ($cityId): array {
                $districts = $this->bosta->listDistrictsForCity($cityId);
                $data = [];
                foreach ($districts as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if (($row['dropOffAvailability'] ?? true) === false) {
                        continue;
                    }
                    $districtId = trim((string) ($row['districtId'] ?? ''));
                    $districtName = trim((string) ($row['districtName'] ?? ''));
                    if ($districtId === '' || $districtName === '') {
                        continue;
                    }
                    $zoneId = trim((string) ($row['zoneId'] ?? ''));
                    $zoneName = trim((string) ($row['zoneName'] ?? ''));
                    $zoneNameAr = trim((string) ($row['zoneOtherName'] ?? ''));
                    $data[] = [
                        'id' => $districtId,
                        'name' => $districtName,
                        'name_ar' => trim((string) ($row['districtOtherName'] ?? '')),
                        'zone_id' => $zoneId !== '' ? $zoneId : null,
                        'zone_name' => $zoneName !== '' ? $zoneName : null,
                        'zone_name_ar' => $zoneNameAr !== '' ? $zoneNameAr : null,
                    ];
                }
                usort($data, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

                return $data;
            }
        );

        return $mapped;
    }

    private function searchTerm(Request $request): string
    {
        $raw = $request->query('search', $request->query('q', ''));

        return mb_strtolower(trim(is_scalar($raw) ? (string) $raw : ''));
    }
}
