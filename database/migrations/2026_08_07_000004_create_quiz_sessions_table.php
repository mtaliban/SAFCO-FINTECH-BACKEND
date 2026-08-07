<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quiz_session is one live Kahoot game.
 * The 6-digit PIN is the join code students enter.
 * Session lifecycle: waiting -> question_active -> question_ended -> ... -> completed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('pin', 6)->unique();

            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();

            $table->enum('mode', ['classic', 'team'])->default('classic');
            $table->enum('status', [
                'waiting',
                'starting',
                'question_active',
                'question_ended',
                'showing_leaderboard',
                'completed',
                'cancelled',
            ])->default('waiting');

            $table->integer('current_question_index')->default(0);
            $table->foreignId('current_question_id')->nullable()
                  ->constrained('questions')->nullOnDelete();
            $table->timestamp('current_question_started_at')->nullable();
            $table->timestamp('current_question_ends_at')->nullable();

            $table->integer('participant_count')->default(0);
            $table->integer('total_questions')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->json('settings')->nullable();
            $table->json('final_leaderboard')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('pin');
        });

        Schema::create('quiz_session_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('session_id')->constrained('quiz_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->string('nickname');
            $table->string('avatar_url')->nullable();
            $table->string('team_name')->nullable();

            $table->integer('total_score')->default(0);
            $table->integer('current_rank')->nullable();
            $table->integer('correct_answers')->default(0);
            $table->integer('incorrect_answers')->default(0);
            $table->integer('longest_streak')->default(0);
            $table->integer('current_streak')->default(0);
            $table->integer('avg_response_time_ms')->default(0);

            $table->string('socket_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('device_type')->nullable();
            $table->boolean('is_connected')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'nickname']);
            $table->index(['session_id', 'total_score']);
        });

        Schema::create('quiz_session_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('quiz_sessions')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('quiz_session_participants')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();

            $table->json('answer');
            $table->boolean('is_correct')->default(false);
            $table->integer('points_earned')->default(0);
            $table->integer('speed_bonus')->default(0);
            $table->integer('streak_bonus')->default(0);
            $table->integer('response_time_ms')->default(0);
            $table->integer('answered_at_position')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'participant_id', 'question_id']);
            $table->index(['session_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_session_answers');
        Schema::dropIfExists('quiz_session_participants');
        Schema::dropIfExists('quiz_sessions');
    }
};
