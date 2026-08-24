<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS Module 13 — Certified Trainer Portal foundation.
 *
 *   trainer_profiles         – 1:1 with User; trainer-specific bio + verification
 *   trainer_qualifications   – education / degrees
 *   trainer_certifications   – external certifications (with expiry)
 *   trainer_experiences      – work history (title, company, dates)
 *   trainer_reviews          – student ratings post-completion (1-5 stars)
 *
 * Design choices:
 *  - trainer_profiles.public_slug is unique + URL-safe (e.g. "amina-hassan-a1b2c3")
 *  - rating_avg + rating_count denormalized on trainer_profiles for cheap directory sort
 *  - verified_at (timestamp) instead of just is_verified boolean → audit trail
 *  - Every proof file lives on the PRIVATE disk; admin gets signed URLs for review
 *  - review uniqueness prevents a student from spamming ratings on the same course
 */
return new class extends Migration {
    public function up(): void
    {
        // ── trainer_profiles ─────────────────────────────────────
        Schema::create('trainer_profiles', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Public URL slug — used in /trainers/{slug}
            $t->string('public_slug', 96)->unique();

            // Professional identity
            $t->string('headline', 180)->nullable();       // e.g. "Senior Excel & Power Query Consultant"
            $t->text('bio_long')->nullable();              // Rich bio for public profile
            $t->unsignedSmallInteger('years_experience')->nullable();
            $t->json('expertise_areas')->nullable();        // ['excel', 'power_query', 'financial_modeling']
            $t->json('teaching_languages')->nullable();     // ['sw', 'en']
            $t->string('timezone', 64)->default('Africa/Dar_es_Salaam');
            $t->unsignedBigInteger('hourly_rate_tzs')->nullable();

            // Availability / visibility
            $t->enum('availability_status', ['available', 'busy', 'unavailable'])
              ->default('available');
            $t->boolean('is_public')->default(false)->index();

            // Verification (issued by admin when all quals+certs verified)
            $t->boolean('is_verified')->default(false)->index();
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            // Denormalized aggregates for cheap directory sort
            $t->decimal('rating_avg', 3, 2)->nullable();   // 0.00 – 5.00
            $t->unsignedInteger('rating_count')->default(0);
            $t->unsignedInteger('students_taught_count')->default(0); // cached from enrollments

            // Contact preferences
            $t->boolean('accepts_direct_inquiries')->default(true);
            $t->string('public_email', 180)->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['is_public', 'is_verified']);
            $t->index('rating_avg');
        });

        // ── trainer_qualifications (education) ───────────────────
        Schema::create('trainer_qualifications', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('trainer_profile_id')->constrained('trainer_profiles')->cascadeOnDelete();

            $t->string('institution', 200);       // e.g. "University of Dar es Salaam"
            $t->string('degree', 150);             // e.g. "BSc Computer Science"
            $t->string('field_of_study', 150)->nullable();
            $t->unsignedSmallInteger('start_year')->nullable();
            $t->unsignedSmallInteger('end_year')->nullable(); // null = ongoing

            // Proof upload
            $t->string('proof_file_path', 500)->nullable();
            $t->string('proof_file_name', 255)->nullable();
            $t->unsignedInteger('proof_file_size')->nullable();
            $t->string('proof_mime_type', 100)->nullable();

            // Verification workflow
            $t->enum('verification_status', ['pending', 'verified', 'rejected'])
              ->default('pending')->index();
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('rejection_reason')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['trainer_profile_id', 'verification_status'], 'tq_profile_status_idx');
        });

        // ── trainer_certifications ───────────────────────────────
        Schema::create('trainer_certifications', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('trainer_profile_id')->constrained('trainer_profiles')->cascadeOnDelete();

            $t->string('name', 200);                   // "Microsoft Certified Trainer"
            $t->string('issuer', 200);                 // "Microsoft"
            $t->string('credential_id', 150)->nullable();
            $t->string('verification_url', 500)->nullable(); // Public issuer verify URL

            $t->date('issue_date')->nullable();
            $t->date('expiry_date')->nullable();       // Null = does not expire

            $t->string('proof_file_path', 500)->nullable();
            $t->string('proof_file_name', 255)->nullable();
            $t->unsignedInteger('proof_file_size')->nullable();
            $t->string('proof_mime_type', 100)->nullable();

            $t->enum('verification_status', ['pending', 'verified', 'rejected'])
              ->default('pending')->index();
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('rejection_reason')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['trainer_profile_id', 'verification_status'], 'tc_profile_status_idx');
            $t->index('expiry_date');
        });

        // ── trainer_experiences ──────────────────────────────────
        Schema::create('trainer_experiences', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('trainer_profile_id')->constrained('trainer_profiles')->cascadeOnDelete();

            $t->string('title', 200);                  // "Head of Corporate Training"
            $t->string('company', 200);                // "SAFCO FINTECH"
            $t->string('location', 200)->nullable();
            $t->date('start_date');
            $t->date('end_date')->nullable();          // null = currently active
            $t->text('description')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index('trainer_profile_id');
        });

        // ── trainer_reviews ──────────────────────────────────────
        Schema::create('trainer_reviews', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();

            $t->foreignId('trainer_profile_id')->constrained('trainer_profiles')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();

            $t->unsignedTinyInteger('rating'); // 1..5
            $t->text('review_text')->nullable();

            // Moderation
            $t->enum('status', ['pending', 'published', 'hidden'])->default('published')->index();
            $t->text('moderation_note')->nullable();
            $t->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('moderated_at')->nullable();

            $t->timestamps();
            $t->softDeletes();

            // A student can leave ONE review per (trainer, course) combination
            $t->unique(['trainer_profile_id', 'student_id', 'course_id'], 'trainer_reviews_unique');
            $t->index(['trainer_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_reviews');
        Schema::dropIfExists('trainer_experiences');
        Schema::dropIfExists('trainer_certifications');
        Schema::dropIfExists('trainer_qualifications');
        Schema::dropIfExists('trainer_profiles');
    }
};
