<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS Module 8 (Examination System) — schema additions.
 *
 * quizzes:
 *   - exam_type: 'practice' | 'mock' | 'final_certification' (meaningful only when mode='exam')
 *   - anti_cheat_settings: JSON bag of proctor rules for exam-mode quizzes
 *   - randomize_from_bank_id: nullable FK — when set, attempt start pulls N random questions from that bank
 *
 * quiz_attempts:
 *   - exam_type: snapshot of the quiz's exam_type at attempt creation (so trainer edits don't retroactively change past attempts)
 *   - violations: JSON array of anti-cheat events { type, at, meta }
 *   - auto_submit_reason: 'duration_exceeded' | 'violations_threshold' | 'browser_closed' | null
 *   - expires_at: hard deadline for the attempt (started_at + duration_minutes)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->enum('exam_type', ['practice', 'mock', 'final_certification'])
                ->nullable()
                ->after('mode');
            $table->json('anti_cheat_settings')->nullable()->after('settings');
            $table->foreignId('randomize_from_bank_id')
                ->nullable()
                ->after('question_bank_id')
                ->constrained('question_banks')
                ->nullOnDelete();
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->enum('exam_type', ['practice', 'mock', 'final_certification'])
                ->nullable()
                ->after('status');
            $table->json('violations')->nullable()->after('question_snapshot');
            $table->string('auto_submit_reason')->nullable()->after('violations');
            $table->timestamp('expires_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['exam_type', 'violations', 'auto_submit_reason', 'expires_at']);
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropForeign(['randomize_from_bank_id']);
            $table->dropColumn(['exam_type', 'anti_cheat_settings', 'randomize_from_bank_id']);
        });
    }
};
