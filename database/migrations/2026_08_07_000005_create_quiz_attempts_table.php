<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-paced quiz attempts (Module 6, non-live mode).
 * Live Kahoot sessions use quiz_session_participants + quiz_session_answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->integer('attempt_number')->default(1);
            $table->enum('status', ['in_progress', 'completed', 'abandoned', 'expired'])
                  ->default('in_progress');

            $table->integer('total_questions')->default(0);
            $table->integer('correct_answers')->default(0);
            $table->integer('incorrect_answers')->default(0);
            $table->integer('unanswered')->default(0);
            $table->integer('total_score')->default(0);
            $table->integer('max_possible_score')->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->boolean('passed')->default(false);

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();

            $table->json('question_snapshot')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'quiz_id']);
            $table->index(['quiz_id', 'total_score']);
        });

        Schema::create('quiz_attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->json('answer');
            $table->boolean('is_correct')->default(false);
            $table->integer('points_earned')->default(0);
            $table->integer('response_time_ms')->default(0);
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_answers');
        Schema::dropIfExists('quiz_attempts');
    }
};
