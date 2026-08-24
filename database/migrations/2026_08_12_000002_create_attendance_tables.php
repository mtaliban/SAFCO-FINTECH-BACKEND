<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS Module 4 — Attendance Management.
 *
 * A trainer opens a class session; students check in (by scanning a QR code
 * or by trainer marking them manually). Late arrivals are auto-flagged
 * against a configurable threshold. Reports show %attendance + absentee list.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();

            // Optional link to a course — physical classroom sessions can be standalone
            $t->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $t->foreignId('trainer_id')->constrained('users')->cascadeOnDelete();

            $t->string('title');
            $t->text('description')->nullable();
            $t->string('location')->nullable(); // e.g., "Room 202, HQ"

            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->unsignedSmallInteger('late_threshold_minutes')->default(10);

            // QR check-in
            $t->string('qr_token', 64)->unique();
            $t->timestamp('qr_expires_at')->nullable();

            $t->enum('status', ['scheduled', 'open', 'closed'])->default('scheduled');
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('closed_at')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['trainer_id', 'status']);
            $t->index('course_id');
            $t->index('starts_at');
        });

        Schema::create('attendance_records', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('attendance_session_id')->constrained('attendance_sessions')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            $t->enum('status', ['present', 'late', 'absent', 'excused'])->default('present');
            $t->enum('method', ['qr', 'manual', 'auto'])->default('qr');
            $t->timestamp('checked_in_at')->nullable();
            $t->text('notes')->nullable();

            // Trainer who marked (if manual) — null if student self-checked-in via QR
            $t->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            $t->unique(['attendance_session_id', 'student_id']);
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
    }
};
