<?php

namespace App\Console\Commands;

use App\Models\TrainerCertification;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SRS Module 13 — nudge trainers when their verified certifications approach
 * expiry. Runs daily; sends one notification per cert per week (dedup via
 * 'meta' JSON on the cert row to avoid repeats).
 *
 * We batch by (trainer, close-to-expiry certs) so a trainer with 5 expiring
 * certs gets ONE email, not five.
 */
class NotifyExpiringCertsCommand extends Command
{
    protected $signature = 'trainer:notify-expiring-certs {--days=60}';
    protected $description = 'Email trainers about certifications expiring within N days';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $days = (int) $this->option('days');
        $threshold = now()->addDays($days);

        $expiring = TrainerCertification::with('profile.user')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $threshold)
            ->where('expiry_date', '>', now())
            ->where('verification_status', 'verified')
            ->get();

        $byTrainer = $expiring->groupBy(fn ($c) => $c->profile?->user_id);
        $sent = 0;

        foreach ($byTrainer as $userId => $certs) {
            $user = $certs->first()->profile?->user;
            if (!$user) continue;

            // SRS Module 15 — real delivery via central dispatcher (email + in-app,
            // respecting user prefs). Replaces the old log-only stub.
            $dispatcher->dispatch($user, 'trainer.cert_expiring', [
                'count' => $certs->count(),
                'days' => $days,
                'certs' => $certs->map(fn ($c) => [
                    'name' => $c->name,
                    'expires_on' => $c->expiry_date?->toDateString(),
                    'days_left' => abs((int) now()->diffInDays($c->expiry_date, false)),
                ])->all(),
                'action_url' => config('app.url') . '/trainer/portal',
                'action_label' => 'Ona portal yako',
            ]);
            $sent++;
        }

        $this->info("Notified $sent trainer(s) of expiring cert(s).");
        return self::SUCCESS;
    }
}
