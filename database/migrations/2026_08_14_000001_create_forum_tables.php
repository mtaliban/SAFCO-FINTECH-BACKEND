<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SRS Module 14 — Discussion Forum foundation.
 *
 *   forum_categories       – seeded taxonomy (questions | ideas | assignments)
 *   forum_threads          – top-level topic (title + opening body)
 *   forum_posts            – replies to a thread; can be nested one level via parent_post_id
 *   forum_votes            – polymorphic votes on threads or posts (-1 / +1)
 *   forum_subscriptions    – users who want notifications for a thread
 *   forum_reports          – moderation reports on threads/posts
 *   forum_attachments      – optional file uploads on threads/posts
 *   forum_mentions         – @user mentions extracted from body for notifications
 *
 * Design notes:
 *  - Root body lives on forum_threads.body (opening post). Replies are forum_posts.
 *    This avoids the "first post is special" special-case in queries.
 *  - Denorm counters (replies_count, votes_score, views_count) on forum_threads
 *    so list pages don't need aggregate joins.
 *  - accepted_post_id nullable — only for `type=question` threads.
 *  - FULLTEXT index on (title, body) for MySQL search.
 *  - Soft deletes on threads + posts so moderation is reversible.
 *  - forum_votes uses (user_id, votable_type, votable_id) unique to guarantee
 *    one vote per user per target — race-safe via unique constraint.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── forum_categories ────────────────────────────────────
        Schema::create('forum_categories', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 60)->unique();          // 'questions' | 'ideas' | 'assignments'
            $t->string('name', 100);                    // Human label
            $t->string('description', 300)->nullable();
            $t->string('icon', 40)->nullable();         // lucide icon name for UI
            $t->string('color', 20)->nullable();        // e.g. 'blue', 'amber'
            // Behaviour flags
            $t->boolean('supports_accepted_answer')->default(false); // Q&A only
            $t->boolean('requires_course_context')->default(false);  // Assignment discussions require assignment_id
            $t->unsignedTinyInteger('sort_order')->default(0);
            $t->timestamps();
        });

        // ── forum_threads ───────────────────────────────────────
        Schema::create('forum_threads', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('category_id')->constrained('forum_categories')->cascadeOnDelete();
            $t->foreignId('author_id')->constrained('users')->cascadeOnDelete();

            // Optional scoping
            $t->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $t->foreignId('assignment_id')->nullable()->constrained('assignments')->nullOnDelete();

            $t->string('title', 220);
            $t->text('body');                          // Opening post
            $t->json('tags')->nullable();              // ['excel', 'pivot-tables']

            // Moderation state
            $t->boolean('is_pinned')->default(false)->index();
            $t->boolean('is_locked')->default(false);  // No new posts allowed
            $t->boolean('is_hidden')->default(false)->index(); // Soft hide by moderator
            $t->text('moderation_note')->nullable();
            $t->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('moderated_at')->nullable();

            // Q&A only — accepted answer post
            $t->foreignId('accepted_post_id')->nullable();

            // Denormalized counters (recomputed via model hooks)
            $t->unsignedInteger('replies_count')->default(0);
            $t->integer('votes_score')->default(0);
            $t->unsignedInteger('views_count')->default(0);
            $t->timestamp('last_activity_at')->nullable()->index();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['category_id', 'is_hidden', 'last_activity_at'], 'ft_cat_hidden_activity');
            $t->index(['course_id', 'is_hidden'], 'ft_course_hidden');
            $t->index(['assignment_id'], 'ft_assignment');
            $t->index(['author_id', 'is_hidden'], 'ft_author_hidden');
        });

        // MySQL FULLTEXT index for search — SQLite (used in unit tests) does not
        // support FULLTEXT, so guard by driver. Search falls back to LIKE for SQLite.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE forum_threads ADD FULLTEXT ft_search_idx (title, body)');
        }

        // ── forum_posts (replies) ───────────────────────────────
        Schema::create('forum_posts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('thread_id')->constrained('forum_threads')->cascadeOnDelete();
            $t->foreignId('author_id')->constrained('users')->cascadeOnDelete();

            // Threading — nullable = direct reply to thread; set = reply to another post
            $t->foreignId('parent_post_id')->nullable();

            $t->text('body');

            // Denormalized
            $t->integer('votes_score')->default(0);
            $t->boolean('is_accepted_answer')->default(false)->index();
            $t->boolean('is_hidden')->default(false)->index();

            // Moderation
            $t->text('moderation_note')->nullable();
            $t->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('moderated_at')->nullable();

            // Edit trail — increment on every edit to signal "edited" in UI
            $t->timestamp('edited_at')->nullable();
            $t->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['thread_id', 'is_hidden', 'created_at'], 'fp_thread_hidden_time');
            $t->index(['author_id'], 'fp_author');
            $t->index(['parent_post_id'], 'fp_parent');
        });

        // Wire up the accepted_post_id FK now that forum_posts exists.
        Schema::table('forum_threads', function (Blueprint $t) {
            $t->foreign('accepted_post_id')->references('id')->on('forum_posts')->nullOnDelete();
        });

        // ── forum_votes (polymorphic) ───────────────────────────
        Schema::create('forum_votes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('votable_type', 60);            // 'thread' | 'post'
            $t->unsignedBigInteger('votable_id');
            $t->tinyInteger('value');                  // -1 | +1
            $t->timestamps();

            // ONE vote per user per target — race-safe via unique constraint
            $t->unique(['user_id', 'votable_type', 'votable_id'], 'fv_unique_vote');
            $t->index(['votable_type', 'votable_id'], 'fv_target');
        });

        // ── forum_subscriptions (notify on new posts) ───────────
        Schema::create('forum_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('thread_id')->constrained('forum_threads')->cascadeOnDelete();
            $t->timestamps();

            $t->unique(['user_id', 'thread_id'], 'fs_unique');
        });

        // ── forum_reports (moderation queue) ────────────────────
        Schema::create('forum_reports', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $t->string('reportable_type', 60);         // 'thread' | 'post'
            $t->unsignedBigInteger('reportable_id');
            $t->string('reason', 60);                  // spam | offensive | off_topic | other
            $t->text('note')->nullable();

            $t->enum('status', ['open', 'resolved', 'dismissed'])->default('open')->index();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution_note')->nullable();

            $t->timestamps();

            $t->index(['reportable_type', 'reportable_id'], 'fr_target');
            // A user can only report a given target once (dedup spam)
            $t->unique(['reporter_id', 'reportable_type', 'reportable_id'], 'fr_unique_report');
        });

        // ── forum_attachments ───────────────────────────────────
        Schema::create('forum_attachments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('attachable_type', 60);         // 'thread' | 'post'
            $t->unsignedBigInteger('attachable_id');
            $t->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $t->string('file_path', 500);              // relative to disk
            $t->string('file_name', 255);
            $t->string('mime_type', 100);
            $t->unsignedInteger('file_size');
            $t->timestamps();

            $t->index(['attachable_type', 'attachable_id'], 'fa_target');
        });

        // ── forum_mentions (parsed @user references) ────────────
        Schema::create('forum_mentions', function (Blueprint $t) {
            $t->id();
            $t->string('mentionable_type', 60);        // 'thread' | 'post'
            $t->unsignedBigInteger('mentionable_id');
            $t->foreignId('mentioned_user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('notified_at')->nullable();
            $t->timestamps();

            $t->unique(['mentionable_type', 'mentionable_id', 'mentioned_user_id'], 'fm_unique');
            $t->index('mentioned_user_id', 'fm_user');
        });

        // ── Seed the three categories from SRS ──────────────────
        DB::table('forum_categories')->insert([
            [
                'slug' => 'questions', 'name' => 'Questions',
                'description' => 'Ask a question and get help from peers and instructors.',
                'icon' => 'HelpCircle', 'color' => 'blue',
                'supports_accepted_answer' => true, 'requires_course_context' => false,
                'sort_order' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'slug' => 'ideas', 'name' => 'Ideas',
                'description' => 'Share ideas, suggestions, or feedback for the community.',
                'icon' => 'Lightbulb', 'color' => 'amber',
                'supports_accepted_answer' => false, 'requires_course_context' => false,
                'sort_order' => 2,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'slug' => 'assignments', 'name' => 'Assignment Discussions',
                'description' => 'Discuss assignments — scoped to a specific assignment.',
                'icon' => 'ClipboardList', 'color' => 'emerald',
                'supports_accepted_answer' => true, 'requires_course_context' => true,
                'sort_order' => 3,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Schema::dropIfExists('forum_mentions');
        Schema::dropIfExists('forum_attachments');
        Schema::dropIfExists('forum_reports');
        Schema::dropIfExists('forum_subscriptions');
        Schema::dropIfExists('forum_votes');
        Schema::dropIfExists('forum_posts');
        Schema::dropIfExists('forum_threads');
        Schema::dropIfExists('forum_categories');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
