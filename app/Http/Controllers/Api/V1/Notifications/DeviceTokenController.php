<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SRS Module 15 — Device token registration (mobile push, deferred).
 *
 * The mobile app calls POST /devices with its FCM/APNs token after login.
 * The token is stored so PushChannel can address it later. Actual send
 * happens when the FCM adapter is wired.
 */
class DeviceTokenController extends Controller
{
    /** POST /devices — register or refresh a token */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'platform' => ['required', 'in:ios,android,web'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);
        $user = $request->user();

        DB::table('device_tokens')->updateOrInsert(
            ['user_id' => $user->id, 'token' => $data['token']],
            [
                'platform' => $data['platform'],
                'app_version' => $data['app_version'] ?? null,
                'last_seen_at' => now(),
                'updated_at' => now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ]
        );
        return $this->success(null, 'Device registered');
    }

    /** DELETE /devices — de-register on logout */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        DB::table('device_tokens')
            ->where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();
        return $this->success(null, 'Device removed');
    }
}
