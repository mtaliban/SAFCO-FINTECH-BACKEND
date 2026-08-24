<?php

namespace App\Services\Notifications\Channels;

use App\Models\User;

/**
 * SRS Module 15 — every channel implementation obeys this contract.
 *
 * send() returns:
 *   ['status' => 'sent', 'preview' => '...', 'subject' => '...']
 *   ['status' => 'skipped', 'reason' => 'no_email_on_file']
 * throws \Throwable on hard failure (dispatcher records failed_at + retries).
 */
interface ChannelContract
{
    public function key(): string;
    public function send(User $user, string $eventKey, array $payload): array;
}
