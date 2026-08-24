<?php

namespace App\Services\Quiz;

use App\Models\Question;

/**
 * Kahoot-style scoring.
 * Base points × (1 - responseTime/timeLimit × 0.5) rounded up
 *
 * A correct answer at t=0 gets 100% of points; at t=timeLimit gets 50%;
 * anything later or wrong gets 0. Streak bonuses are added on top.
 */
class ScoringService
{
    protected const STREAK_BONUS = [
        0 => 0,   // no streak
        1 => 0,   // first correct
        2 => 100, // 2 in a row
        3 => 200, // 3 in a row
        4 => 300, // 4+ in a row
    ];

    public function score(
        Question $question,
        mixed $submittedAnswer,
        int $responseTimeMs,
        int $currentStreak = 0,
        ?int $overridePoints = null,
        ?int $overrideTimeSeconds = null,
        bool $awardSpeedBonus = true,
    ): array {
        $isCorrect = $question->checkAnswer($submittedAnswer);

        if (! $isCorrect) {
            return [
                'is_correct' => false,
                'base_points' => 0,
                'speed_bonus' => 0,
                'streak_bonus' => 0,
                'total' => 0,
            ];
        }

        $basePoints = $overridePoints ?? $question->points;
        $timeLimit = $overrideTimeSeconds ?? $question->time_limit_seconds;
        $timeLimitMs = max($timeLimit * 1000, 1);

        if ($awardSpeedBonus) {
            // Kahoot formula: 1 - (responseTime / timeLimit) * 0.5, clamped ≥ 50%
            $timeFactor = max(0.5, 1 - min(1, $responseTimeMs / $timeLimitMs) * 0.5);
            $earned = (int) ceil($basePoints * $timeFactor);
            $speedBonus = max(0, $earned - (int) ($basePoints * 0.5));
        } else {
            // No speed bonus — flat correct = full base points
            $earned = (int) $basePoints;
            $speedBonus = 0;
        }

        $streakLevel = min(4, $currentStreak);
        $streakBonus = self::STREAK_BONUS[$streakLevel] ?? 0;

        return [
            'is_correct' => true,
            'base_points' => $earned,
            'speed_bonus' => $speedBonus,
            'streak_bonus' => $streakBonus,
            'total' => $earned + $streakBonus,
        ];
    }
}
