<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Models\UserNotificationPref;
use App\Services\Notifications\EventCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS Module 15 — Notification preferences endpoints.
 *
 * The user sees a matrix (events × channels). Missing rows mean "use the
 * catalog default", so we return the effective (resolved) enabled flag so
 * the UI doesn't have to know about defaults.
 */
class PreferencesController extends Controller
{
    /** GET /notifications/preferences — matrix + metadata */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $catalog = EventCatalog::all();
        $activeChannels = EventCatalog::activeChannels();
        $allChannels = EventCatalog::allChannels();

        // Fetch stored prefs indexed by "event_key|channel"
        $stored = UserNotificationPref::where('user_id', $user->id)
            ->get()
            ->keyBy(fn ($p) => $p->event_key . '|' . $p->channel);

        $events = [];
        foreach ($catalog as $key => $meta) {
            $row = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'category' => $meta['category'],
                'critical' => $meta['critical'],
                'channels' => [],
            ];
            foreach ($allChannels as $ch) {
                $storedKey = $key . '|' . $ch;
                $isActive = in_array($ch, $activeChannels, true);
                $isDefault = in_array($ch, $meta['default_channels'], true);
                $enabled = $stored->has($storedKey)
                    ? (bool) $stored[$storedKey]->enabled
                    : $isDefault;
                $row['channels'][$ch] = [
                    'enabled' => $meta['critical'] ? true : $enabled,
                    'available' => $isActive,           // is the channel actually delivering today?
                    'locked' => $meta['critical'],       // critical events cannot be turned off
                ];
            }
            $events[] = $row;
        }

        return $this->success([
            'categories' => EventCatalog::CATEGORIES,
            'channels' => $allChannels,
            'active_channels' => $activeChannels,
            'events' => $events,
        ]);
    }

    /** PUT /notifications/preferences — bulk update */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prefs' => ['required', 'array'],
            'prefs.*.event_key' => ['required', 'string', 'max:60'],
            'prefs.*.channel' => ['required', 'string', 'in:' . implode(',', EventCatalog::allChannels())],
            'prefs.*.enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $catalog = EventCatalog::all();
        $updates = 0;

        foreach ($data['prefs'] as $entry) {
            $key = $entry['event_key'];
            // Silently ignore unknown events + attempts to turn off critical items.
            if (!isset($catalog[$key])) continue;
            if ($catalog[$key]['critical'] && !$entry['enabled']) continue;

            UserNotificationPref::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'event_key' => $key,
                    'channel' => $entry['channel'],
                ],
                ['enabled' => $entry['enabled']],
            );
            $updates++;
        }

        return $this->success(['updated' => $updates], 'Preferences saved');
    }
}
