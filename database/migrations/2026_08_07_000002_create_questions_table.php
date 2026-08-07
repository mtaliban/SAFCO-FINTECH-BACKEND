<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A question can be one of six SRS-defined types.
 * `options` and `correct_answer` are JSON to accommodate every type:
 *
 *   multiple_choice: options=[{id,label,color,shape}], correct_answer="A"
 *   true_false     : options=[{"true"},{"false"}],     correct_answer=true
 *   multiple_select: options=[{id,label,...}],         correct_answer=["A","C"]
 *   fill_in_blank  : options=null,                      correct_answer="SUM"
 *   matching       : options=[{left,right}],            correct_answer={left:right,...}
 *   short_answer   : options=null,                      correct_answer=null (manual grading)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('question_bank_id')->nullable()
                  ->constrained('question_banks')->nullOnDelete();
            $table->foreignId('created_by')->nullable()
                  ->constrained('users')->nullOnDelete();

            $table->enum('type', [
                'multiple_choice',
                'true_false',
                'multiple_select',
                'fill_in_blank',
                'matching',
                'short_answer',
            ]);

            $table->text('text');
            $table->text('explanation')->nullable();
            $table->string('image_url')->nullable();
            $table->string('audio_url')->nullable();
            $table->string('video_url')->nullable();

            $table->json('options')->nullable();
            $table->json('correct_answer')->nullable();

            $table->integer('points')->default(1000);
            $table->integer('time_limit_seconds')->default(20);
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');

            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);

            $table->integer('times_used')->default(0);
            $table->decimal('avg_correct_rate', 5, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['question_bank_id', 'type']);
            $table->index('difficulty');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
