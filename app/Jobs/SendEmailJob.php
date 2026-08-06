<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [10, 30, 60, 300, 900];
    public int $timeout = 60;
    public string $queue = 'emails';

    public function __construct(
        public string $to,
        public Mailable $mailable,
    ) {
    }

    public function handle(): void
    {
        Mail::to($this->to)->send($this->mailable);
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
    }
}
