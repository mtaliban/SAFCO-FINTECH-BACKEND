<?php

use Illuminate\Support\Facades\Schedule;

// Relay outbox events every minute (Transactional Outbox pattern)
Schedule::command('events:relay --batch=500')->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Clean up expired OTP codes daily
Schedule::command('otp:cleanup')->daily();

// Rotate CSV analytics logs weekly
Schedule::command('logs:rotate-csv')->weekly();

// Prune old login history (keep 90 days)
Schedule::command('login-history:prune --days=90')->weekly();

// SRS M12 — release stale pending payments so the row doesn't stay pending forever
Schedule::command('payments:expire-stale')->everyFiveMinutes()->withoutOverlapping();

// SRS M13 — nudge trainers about certifications approaching expiry
Schedule::command('trainer:notify-expiring-certs --days=60')->dailyAt('08:00')->withoutOverlapping();

// SRS M15 — retry failed notification deliveries every 5 min (exponential backoff)
Schedule::command('notifications:retry')->everyFiveMinutes()->withoutOverlapping();

// SRS M15 — prune old delivery audit rows weekly
Schedule::command('notifications:prune')->weeklyOn(1, '02:00');

// SRS Non-Functional Requirements — backup schedule
// Daily DB dump at 02:30 (retain 7)
Schedule::command('backup:run --type=daily')->dailyAt('02:30')->withoutOverlapping();
// Weekly full backup (DB + files) Sundays 03:00 (retain 4)
Schedule::command('backup:run --type=weekly')->weeklyOn(0, '03:00')->withoutOverlapping();
// Monthly archive on the 1st at 04:00 (retain 12)
Schedule::command('backup:run --type=monthly')->monthlyOn(1, '04:00')->withoutOverlapping();
