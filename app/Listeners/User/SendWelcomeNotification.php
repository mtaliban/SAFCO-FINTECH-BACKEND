<?php

namespace App\Listeners\User;

use App\Events\User\UserRegistered;
use App\Jobs\SendEmailJob;
use App\Jobs\SendSmsJob;
use App\Mail\WelcomeMail;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendWelcomeNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(UserRegistered $event): void
    {
        $user = $event->user->fresh('profile');

        if ($user->email) {
            SendEmailJob::dispatch(
                to: $user->email,
                mailable: new WelcomeMail($user),
            );
        }

        if ($user->phone) {
            SendSmsJob::dispatch(
                phone: $user->phone,
                message: sprintf(
                    'Karibu %s SAFCO FINTECH LMS! Anza safari yako ya kujifunza sasa: %s',
                    $user->profile?->first_name ?? '',
                    config('app.frontend_url')
                )
            );
        }
    }
}
