<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 4.2 Lesson contains: Video, PDF Notes, Assignments.
 * Assignments will get their own table in a later migration.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('course_module_id')->constrained('course_modules')->cascadeOnDelete();

            $t->string('title');
            $t->text('description')->nullable();
            $t->longText('content')->nullable(); // markdown / rich text notes

            // Media — URLs (uploads write to storage/app/public and return URL)
            $t->string('video_url')->nullable();
            $t->string('pdf_url')->nullable();

            $t->integer('duration_seconds')->nullable();
            $t->integer('position')->default(0);

            $t->timestamps();
            $t->softDeletes();

            $t->index(['course_module_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
