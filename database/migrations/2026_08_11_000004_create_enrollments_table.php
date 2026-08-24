<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 3.3 Student "Enroll in courses" + "Track progress".
 * Also used by Corporate Client (SRS 3.4) to monitor employee progress.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('course_id')->constrained('courses')->cascadeOnDelete();

            $t->timestamp('enrolled_at');
            $t->decimal('progress_percentage', 5, 2)->default(0);
            $t->timestamp('completed_at')->nullable();

            $t->timestamps();

            $t->unique(['user_id', 'course_id']); // one enrollment per (user, course)
            $t->index('course_id');
        });

        // Track which lessons each learner has completed.
        Schema::create('lesson_completions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $t->timestamp('completed_at');
            $t->timestamps();

            $t->unique(['user_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_completions');
        Schema::dropIfExists('enrollments');
    }
};
