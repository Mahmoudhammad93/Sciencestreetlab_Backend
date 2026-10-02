<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Infrastructure\Shipping\Bosta\HttpBostaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Safe public location options for checkout — proxies official Bosta cities/districts
 * without exposing API credentials to the browser.
 */
final class BostaLocationsController extends Controller
{
    public function __construct(
        private readonly HttpBostaClient $bosta,
    ) {}

    public function cities(): JsonResponse
    {
        if (! (bool) config('bosta.enabled')) {
            return response()->json(['data' => []]);
        }

        try {
            $cities = $this->bosta->listCities();
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to load shipping cities.',
            ], 503);
        }

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

        return response()->json(['data' => $data]);
    }

    public function districts(Request $request, string $cityId): JsonResponse
    {
        $cityId = trim($cityId);
        if ($cityId === '' || strlen($cityId) > 64) {
            return response()->json(['message' => 'Invalid city id.'], 422);
        }

        if (! (bool) config('bosta.enabled')) {
            return response()->json(['data' => []]);
        }

        try {
            $districts = $this->bosta->listDistrictsForCity($cityId);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to load shipping districts.',
            ], 503);
        }

        $q = mb_strtolower(trim((string) $request->query('q', '')));

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
            $nameAr = trim((string) ($row['districtOtherName'] ?? ''));
            $zoneId = trim((string) ($row['zoneId'] ?? ''));
            $zoneName = trim((string) ($row['zoneName'] ?? ''));
            $zoneNameAr = trim((string) ($row['zoneOtherName'] ?? ''));

            if ($q !== '') {
                $hay = mb_strtolower($districtName.' '.$nameAr.' '.$zoneName.' '.$zoneNameAr);
                if (! str_contains($hay, $q)) {
                    continue;
                }
            }

            $data[] = [
                'id' => $districtId,
                'name' => $districtName,
                'name_ar' => $nameAr,
                'zone_id' => $zoneId !== '' ? $zoneId : null,
                'zone_name' => $zoneName !== '' ? $zoneName : null,
                'zone_name_ar' => $zoneNameAr !== '' ? $zoneNameAr : null,
            ];
        }

        usort($data, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return response()->json(['data' => $data]);
    }
}
