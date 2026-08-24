<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 4.2 Module 2: Course Management
 *
 * Workflow: draft → pending_approval → published (or rejected).
 * SRS 3.1 System Administrator "Approve courses" — admin approves.
 * SRS 3.2 Trainer "Create courses" — trainer authors + submits.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('slug')->unique();

            $t->string('title');
            $t->text('description')->nullable();

            // Categories match the Quizzes enum + SRS 4.2 list (10 categories)
            $t->enum('category', [
                'microsoft_office', 'excel', 'power_query', 'power_bi',
                'accounting', 'finance', 'ifrs', 'erp_systems',
                'coding', 'data_analytics', 'general',
            ])->default('general');

            $t->enum('level', ['beginner', 'intermediate', 'advanced', 'expert'])
              ->default('beginner');

            // Duration is trainer-declared (in hours). Actual computed from lessons later.
            $t->integer('duration_hours')->nullable();

            $t->string('thumbnail_url')->nullable();

            // Instructor = SRS "Instructor" field. Points to a User with the trainer role.
            $t->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('created_by')->constrained('users');

            // Approval workflow (SRS 3.1: admin approves courses)
            $t->enum('status', ['draft', 'pending_approval', 'published', 'rejected', 'archived'])
              ->default('draft');
            $t->text('rejection_reason')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('published_at')->nullable();

            $t->json('metadata')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['status', 'category']);
            $t->index('instructor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
