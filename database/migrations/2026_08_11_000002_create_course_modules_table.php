<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 4.2 Course Structure:
 *   Course → Modules → Lessons + Quiz
 *
 * A "module" is a chapter/section of a course.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('course_modules', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->integer('position')->default(0);
            $t->timestamps();
            $t->softDeletes();

            $t->index(['course_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_modules');
    }
};
