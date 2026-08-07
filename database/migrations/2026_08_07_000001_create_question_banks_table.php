<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A question bank is a reusable collection of questions grouped by
 * subject (Excel, Power BI, IFRS, etc.).  A single question bank can
 * feed many quizzes and live sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_id')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('category', [
                'microsoft_office', 'excel', 'power_query', 'power_bi',
                'accounting', 'finance', 'ifrs', 'erp_systems',
                'coding', 'data_analytics', 'general',
            ])->default('general');
            $table->enum('difficulty', ['beginner', 'intermediate', 'advanced', 'expert'])
                  ->default('beginner');
            $table->boolean('is_public')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('total_questions')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'is_active']);
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_banks');
    }
};
