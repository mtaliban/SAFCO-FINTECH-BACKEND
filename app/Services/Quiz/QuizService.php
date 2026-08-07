<?php

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class QuizService
{
    public function createQuiz(User $user, array $data): Quiz
    {
        return Quiz::create(array_merge($data, ['created_by' => $user->id]));
    }

    public function updateQuiz(Quiz $quiz, array $data): Quiz
    {
        $quiz->update($data);
        return $quiz->fresh();
    }

    /**
     * Attach or reorder questions in a quiz.
     * $items = [['question_id' => 5, 'position' => 0, 'override_time_seconds' => 20, 'override_points' => 1000], ...]
     */
    public function syncQuestions(Quiz $quiz, array $items): Quiz
    {
        return DB::transaction(function () use ($quiz, $items) {
            $sync = [];
            foreach ($items as $i => $item) {
                $sync[$item['question_id']] = [
                    'position' => $item['position'] ?? $i,
                    'override_time_seconds' => $item['override_time_seconds'] ?? null,
                    'override_points' => $item['override_points'] ?? null,
                ];
            }
            $quiz->questions()->sync($sync);
            $quiz->update(['number_of_questions' => count($items)]);
            return $quiz->fresh(['questions']);
        });
    }

    public function publish(Quiz $quiz): Quiz
    {
        if ($quiz->questions()->count() === 0) {
            throw new \DomainException('Cannot publish a quiz with no questions.');
        }

        $quiz->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $quiz->fresh();
    }

    public function archive(Quiz $quiz): Quiz
    {
        $quiz->update(['status' => 'archived']);
        return $quiz->fresh();
    }
}
