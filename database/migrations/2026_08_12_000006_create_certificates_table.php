<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS Module 10 — Certificate Management.
 *
 * Certificate row is IMMUTABLE once issued:
 *   - student_name_snapshot / course_title_snapshot are copies so future edits
 *     to the User's profile or Course title never rewrite the historical record.
 *   - verification_hash is SHA256(cert_number|user_id|course_id|APP_KEY) — regenerated
 *     on the fly for verification; if the cert_number is tampered the hash won't match.
 *   - cert_number is human-shareable (SAFCO-2026-XXXXXXXX), also indexed for public lookup.
 *   - unique(user_id, course_id) prevents duplicate certs for the same course completion.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('cert_number', 32)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_attempt_id')->nullable()->constrained('quiz_attempts')->nullOnDelete();

            // Frozen at issue time so historical certificates keep their names
            $table->string('student_name_snapshot');
            $table->string('course_title_snapshot');

            $table->date('completion_date');
            $table->timestamp('issued_at');
            $table->decimal('score_percentage', 5, 2)->nullable();

            // Anti-forgery — SHA256(cert_number|user_id|course_id|APP_KEY)
            $table->string('verification_hash', 64);

            $table->enum('status', ['active', 'revoked', 'expired'])->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // A student can hold ONE certificate per course
            $table->unique(['user_id', 'course_id']);
            $table->index(['status', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
