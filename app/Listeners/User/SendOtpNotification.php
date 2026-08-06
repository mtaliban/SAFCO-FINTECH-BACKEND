<?php

namespace App\Listeners\User;

use App\Events\User\OtpRequested;
use App\Jobs\SendEmailJob;
use App\Jobs\SendSmsJob;
use App\Mail\OtpMail;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOtpNotification implements ShouldQueue
{
    public string $queue = 'notifications-high';

    public function handle(OtpRequested $event): void
    {
        $message = sprintf(
            'Your SAFCO LMS %s code is: %s. Expires in %d minutes.',
            str_replace('_', ' ', $event->type),
            $event->code,
            (int) config('auth.otp.expiry_minutes', 5)
        );

        match ($event->channel) {
            'sms' => SendSmsJob::dispatch($event->identifier, $message),
            'email' => SendEmailJob::dispatch(
                $event->identifier,
                new OtpMail($event->code, $event->type)
            ),
            default => null,
        };
    }
}
