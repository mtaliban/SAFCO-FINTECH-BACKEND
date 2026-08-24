<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 4.2 "Course Contains: Modules, Lessons, Assessments".
 * The Course-level Assessment is a single final quiz that a student takes
 * to receive completion + certificate.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $t) {
            $t->foreignId('final_assessment_quiz_id')->nullable()->after('duration_hours')
                ->constrained('quizzes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $t) {
            $t->dropForeign(['final_assessment_quiz_id']);
            $t->dropColumn('final_assessment_quiz_id');
        });
    }
};
