<?php

namespace App\Listeners\Analytics;

use App\Events\BaseEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Appends every domain event as a row in a daily CSV file.
 * These files are exported for offline analytics and model training.
 * Path: storage/app/logs/csv/events-YYYY-MM-DD.csv
 */
class LogEventToCsv implements ShouldQueue
{
    public string $queue = 'analytics';

    public function handle(BaseEvent $event): void
    {
        if (! config('logging.csv_export.enabled', true)) {
            return;
        }

        $date = now()->format('Y-m-d');
        $path = "logs/csv/events-{$date}.csv";
        $disk = Storage::disk('local');

        $isNew = ! $disk->exists($path);

        $row = [
            'event_id' => $event->eventId,
            'event_name' => $event->eventName,
            'occurred_at' => $event->occurredAt,
            'correlation_id' => $event->correlationId,
            'payload_json' => json_encode($event->toPayload()),
        ];

        $handle = fopen('php://temp', 'r+');
        if ($isNew) {
            fputcsv($handle, array_keys($row));
        }
        fputcsv($handle, array_values($row));
        rewind($handle);
        $csvLine = stream_get_contents($handle);
        fclose($handle);

        if ($isNew) {
            $disk->put($path, $csvLine);
        } else {
            $disk->append($path, rtrim($csvLine, "\n"));
        }
    }
}
