<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Quiz is a curated set of questions the trainer publishes. It carries
 * the settings the SRS asks for: name, duration, number of questions,
 * passing mark, attempts, timer, and can be used in three modes:
 *   - self_paced : student takes it on their own
 *   - live_kahoot: trainer hosts a live session (Module 7)
 *   - exam       : timed, single-attempt exam
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('question_bank_id')->nullable()
                  ->constrained('question_banks')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('cover_image')->nullable();

            $table->enum('mode', ['self_paced', 'live_kahoot', 'exam'])->default('self_paced');
            $table->enum('category', [
                'microsoft_office', 'excel', 'power_query', 'power_bi',
                'accounting', 'finance', 'ifrs', 'erp_systems',
                'coding', 'data_analytics', 'general',
            ])->default('general');
            $table->enum('difficulty', ['beginner', 'intermediate', 'advanced', 'expert'])
                  ->default('beginner');

            $table->integer('duration_minutes')->nullable();
            $table->integer('number_of_questions')->default(10);
            $table->integer('passing_mark_percentage')->default(50);
            $table->integer('max_attempts')->default(3);
            $table->integer('default_time_per_question')->default(20);

            $table->boolean('shuffle_questions')->default(true);
            $table->boolean('shuffle_options')->default(true);
            $table->boolean('show_correct_after_each')->default(true);
            $table->boolean('show_leaderboard')->default(true);
            $table->boolean('award_bonus_for_speed')->default(true);
            $table->boolean('allow_late_join')->default(true);

            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->json('settings')->nullable();
            $table->integer('total_plays')->default(0);
            $table->decimal('avg_score', 5, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'mode']);
            $table->index('category');
            $table->index('slug');
        });

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->integer('override_time_seconds')->nullable();
            $table->integer('override_points')->nullable();
            $table->timestamps();

            $table->unique(['quiz_id', 'question_id']);
            $table->index(['quiz_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
    }
};
