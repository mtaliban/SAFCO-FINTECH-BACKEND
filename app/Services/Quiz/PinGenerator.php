<?php

namespace App\Services\Quiz;

use App\Models\QuizSession;

/**
 * Generates a unique 6-digit PIN for a live Kahoot session.
 * Retries on collision (extremely rare given 1M possible PINs).
 */
class PinGenerator
{
    public function generate(): string
    {
        for ($i = 0; $i < 25; $i++) {
            $pin = str_pad((string) random_int(100_000, 999_999), 6, '0', STR_PAD_LEFT);

            $exists = QuizSession::where('pin', $pin)
                ->whereIn('status', ['waiting', 'starting', 'question_active', 'question_ended', 'showing_leaderboard'])
                ->exists();

            if (! $exists) {
                return $pin;
            }
        }

        throw new \RuntimeException('Failed to generate a unique PIN after 25 attempts');
    }
}
