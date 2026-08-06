<?php

namespace App\Jobs;

use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends an SMS via Africa's Talking HTTP API (primary provider in Tanzania).
 * Uses raw Guzzle instead of the AT SDK to avoid dependency conflicts with
 * Laravel 12's flysystem requirement.
 */
class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [10, 30, 60, 180, 600];
    public string $queue = 'sms';

    public function __construct(
        public string $phone,
        public string $message,
    ) {
    }

    public function handle(): void
    {
        $username = config('services.africastalking.username');
        $apiKey = config('services.africastalking.api_key');
        $senderId = config('services.africastalking.sender_id', 'SAFCO');
        $env = config('services.africastalking.environment', 'sandbox');

        if (! $username || ! $apiKey) {
            Log::warning('Africa\'s Talking credentials missing, skipping SMS', [
                'phone' => $this->phone,
            ]);
            return;
        }

        $baseUrl = $env === 'production'
            ? 'https://api.africastalking.com'
            : 'https://api.sandbox.africastalking.com';

        $client = new Client(['base_uri' => $baseUrl, 'timeout' => 30]);

        $response = $client->post('/version1/messaging', [
            'headers' => [
                'apiKey' => $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'username' => $username,
                'to' => $this->normalizePhone($this->phone),
                'message' => $this->message,
                'from' => $senderId,
            ],
        ]);

        Log::info('SMS dispatched', [
            'phone' => $this->phone,
            'status_code' => $response->getStatusCode(),
        ]);
    }

    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '+255' . substr($phone, 1);
        } elseif (! str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }
}
