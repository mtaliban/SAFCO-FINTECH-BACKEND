<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 4.2 "Lesson contains Assignments".
 * Assignment attached to a lesson; students submit files/text; trainer grades.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $t->string('title');
            $t->text('instructions')->nullable();
            $t->integer('max_points')->default(100);
            $t->timestamp('due_date')->nullable();
            $t->json('allowed_file_types')->nullable(); // ['pdf', 'docx', ...]
            $t->timestamps();
            $t->softDeletes();

            $t->index('lesson_id');
        });

        Schema::create('assignment_submissions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $t->text('answer_text')->nullable();
            $t->string('file_url')->nullable();
            $t->timestamp('submitted_at');
            $t->enum('status', ['submitted', 'graded', 'returned'])->default('submitted');
            $t->integer('grade')->nullable();     // out of max_points
            $t->text('feedback')->nullable();
            $t->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('graded_at')->nullable();
            $t->timestamps();

            $t->unique(['assignment_id', 'student_id']); // one submission per (assignment, student)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignments');
    }
};
