<?php

namespace App\Services\Quiz;

use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class QuizService
{
    public function createQuiz(User $user, array $data): Quiz
    {
        $quiz = Quiz::create(array_merge($data, ['created_by' => $user->id]));
        return $quiz->fresh();
    }

    public function updateQuiz(Quiz $quiz, array $data): Quiz
    {
        $quiz->update($data);
        return $quiz->fresh();
    }

    /**
     * Replace all attached questions with the given ordered set.
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

    /**
     * Append the given question IDs to the end of the quiz (skip already attached).
     * Returns count of newly attached.
     */
    public function attachQuestions(Quiz $quiz, array $questionIds): array
    {
        return DB::transaction(function () use ($quiz, $questionIds) {
            $existingIds = $quiz->questions()->pluck('questions.id')->all();
            $newIds = array_values(array_diff(array_map('intval', $questionIds), $existingIds));
            if (empty($newIds)) {
                return ['attached' => 0, 'total' => count($existingIds)];
            }

            $nextPos = ($quiz->questions()->max('quiz_questions.position') ?? -1) + 1;
            $attach = [];
            foreach ($newIds as $i => $id) {
                $attach[$id] = [
                    'position' => $nextPos + $i,
                    'override_time_seconds' => null,
                    'override_points' => null,
                ];
            }
            $quiz->questions()->attach($attach);
            $quiz->update(['number_of_questions' => count($existingIds) + count($newIds)]);

            return ['attached' => count($newIds), 'total' => count($existingIds) + count($newIds)];
        });
    }

    /**
     * Pick $count random questions from $bank (respecting optional filters) and append.
     * Returns [attached_count, total, skipped_already_attached].
     */
    public function attachRandomFromBank(Quiz $quiz, QuestionBank $bank, int $count, array $filters = []): array
    {
        $q = $bank->questions()->where('is_active', true);
        if (!empty($filters['type']))       $q->where('type', $filters['type']);
        if (!empty($filters['difficulty'])) $q->where('difficulty', $filters['difficulty']);

        // Exclude questions already attached to this quiz to avoid churn
        $existingIds = $quiz->questions()->pluck('questions.id')->all();
        if (!empty($existingIds)) $q->whereNotIn('id', $existingIds);

        $picked = $q->inRandomOrder()->limit($count)->pluck('id')->all();
        if (empty($picked)) {
            return ['attached' => 0, 'total' => count($existingIds), 'requested' => $count];
        }

        $result = $this->attachQuestions($quiz, $picked);
        $result['requested'] = $count;
        return $result;
    }

    /**
     * Detach the given question IDs. Positions of remaining questions are renumbered.
     */
    public function detachQuestions(Quiz $quiz, array $questionIds): array
    {
        return DB::transaction(function () use ($quiz, $questionIds) {
            $ids = array_map('intval', $questionIds);
            $quiz->questions()->detach($ids);

            // Renumber remaining positions (0..n-1) preserving current order
            $remaining = $quiz->questions()
                ->orderBy('quiz_questions.position')
                ->pluck('questions.id')
                ->all();
            $sync = [];
            foreach ($remaining as $i => $id) {
                $sync[$id] = ['position' => $i];
            }
            if ($sync) $quiz->questions()->syncWithoutDetaching($sync);

            $quiz->update(['number_of_questions' => count($remaining)]);
            return ['detached' => count($ids), 'total' => count($remaining)];
        });
    }

    /**
     * Reorder questions in the quiz by the given ordered list of question IDs.
     * IDs not in the list are left unchanged (placed at end preserving relative order).
     */
    public function reorderQuestions(Quiz $quiz, array $orderedIds): Quiz
    {
        return DB::transaction(function () use ($quiz, $orderedIds) {
            $orderedIds = array_map('intval', $orderedIds);
            $sync = [];
            foreach ($orderedIds as $i => $id) {
                $sync[$id] = ['position' => $i];
            }
            if ($sync) $quiz->questions()->syncWithoutDetaching($sync);
            return $quiz->fresh(['questions']);
        });
    }

    /**
     * Clone a quiz (as draft) with all its attached questions in the same order.
     * Same trainer becomes owner of the copy.
     */
    public function duplicateQuiz(Quiz $source, User $user): Quiz
    {
        return DB::transaction(function () use ($source, $user) {
            $data = $source->only([
                'question_bank_id', 'course_module_id', 'name', 'description', 'cover_image',
                'mode', 'category', 'difficulty', 'duration_minutes', 'number_of_questions',
                'passing_mark_percentage', 'max_attempts', 'default_time_per_question',
                'shuffle_questions', 'shuffle_options', 'show_correct_after_each',
                'show_leaderboard', 'award_bonus_for_speed', 'allow_late_join', 'settings',
            ]);
            $data['name'] = trim($source->name . ' (Copy)');
            $data['status'] = 'draft';
            $data['published_at'] = null;
            $data['created_by'] = $user->id;

            $copy = Quiz::create($data);

            // Copy attached questions with same position/overrides
            $rows = DB::table('quiz_questions')->where('quiz_id', $source->id)->get();
            $attach = [];
            foreach ($rows as $r) {
                $attach[$r->question_id] = [
                    'position' => $r->position,
                    'override_time_seconds' => $r->override_time_seconds,
                    'override_points' => $r->override_points,
                ];
            }
            if ($attach) $copy->questions()->attach($attach);

            return $copy->fresh(['questions']);
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
