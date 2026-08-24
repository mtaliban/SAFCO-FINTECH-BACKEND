<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS 4.2 "Module contains Lessons + Quiz" — allow attaching a quiz to a course module.
 * Nullable so standalone quizzes (not tied to any course) still work.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $t) {
            $t->foreignId('course_module_id')->nullable()->after('id')
                ->constrained('course_modules')->nullOnDelete();
            $t->index('course_module_id');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $t) {
            $t->dropForeign(['course_module_id']);
            $t->dropIndex(['course_module_id']);
            $t->dropColumn('course_module_id');
        });
    }
};
