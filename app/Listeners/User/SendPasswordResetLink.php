<?php

namespace App\Listeners\User;

use App\Events\User\PasswordResetRequested;
use App\Jobs\SendEmailJob;
use App\Mail\PasswordResetMail;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPasswordResetLink implements ShouldQueue
{
    public string $queue = 'notifications-high';

    public function handle(PasswordResetRequested $event): void
    {
        $resetUrl = sprintf(
            '%s/reset-password?token=%s&email=%s',
            rtrim(config('app.frontend_url'), '/'),
            $event->token,
            urlencode($event->user->email)
        );

        SendEmailJob::dispatch(
            $event->user->email,
            new PasswordResetMail($event->user, $resetUrl)
        );
    }
}
