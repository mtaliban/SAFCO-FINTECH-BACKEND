<?php

namespace App\Services\Quiz;

use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class QuestionBankService
{
    public function createBank(User $user, array $data): QuestionBank
    {
        return QuestionBank::create(array_merge($data, ['owner_id' => $user->id]));
    }

    public function addQuestion(QuestionBank $bank, User $user, array $data): Question
    {
        return DB::transaction(function () use ($bank, $user, $data) {
            $question = Question::create(array_merge($data, [
                'question_bank_id' => $bank->id,
                'created_by' => $user->id,
            ]));

            $bank->increment('total_questions');
            return $question;
        });
    }

    public function updateQuestion(Question $question, array $data): Question
    {
        $question->update($data);
        return $question->fresh();
    }

    public function deleteQuestion(Question $question): void
    {
        DB::transaction(function () use ($question) {
            $bank = $question->bank;
            $question->delete();
            $bank?->decrement('total_questions');
        });
    }

    /**
     * Attach standard Kahoot colors + shapes to a multiple-choice question's options
     * if the caller didn't provide them.
     */
    public function styleOptions(array $options): array
    {
        $styles = Question::OPTION_STYLES;
        return array_map(function ($option, $i) use ($styles) {
            return array_merge(
                ['id' => chr(65 + $i), 'color' => $styles[$i]['color'] ?? null, 'shape' => $styles[$i]['shape'] ?? null],
                $option
            );
        }, $options, array_keys($options));
    }
}
