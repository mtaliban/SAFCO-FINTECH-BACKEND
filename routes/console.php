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
