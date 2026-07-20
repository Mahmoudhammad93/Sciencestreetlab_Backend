<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Infrastructure\Persistence\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:100'],
            'fcm_token' => ['nullable', 'string', 'max:500'],
            'platform' => ['required', 'in:ios,android,web'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);

        $device = UserDevice::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'device_id' => $validated['device_id'],
            ],
            [
                'fcm_token' => $validated['fcm_token'] ?? null,
                'platform' => $validated['platform'],
                'app_version' => $validated['app_version'] ?? $request->header('X-App-Version'),
                'last_active_at' => now(),
            ]
        );

        return response()->json(['data' => $device], 201);
    }

    public function destroy(Request $request, string $deviceId): JsonResponse
    {
        UserDevice::query()
            ->where('user_id', $request->user()->id)
            ->where('device_id', $deviceId)
            ->delete();

        return response()->json(['message' => 'Device unregistered']);
    }
}
