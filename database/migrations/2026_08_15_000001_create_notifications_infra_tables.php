<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS Module 15 — Notifications infrastructure.
 *
 *   user_notification_prefs    – per-user (event × channel) opt-in toggle
 *   notification_deliveries    – audit log per attempt (queued/sent/failed/delivered)
 *   broadcast_announcements    – admin one-off messages sent to a segment
 *   device_tokens              – mobile push infra (stubbed; used by later mobile app)
 *
 * Design choices:
 *  - prefs default to TRUE if row absent — new events are opt-out, not opt-in,
 *    so we don't silently drop notifications for users who never touched settings.
 *  - critical events (security_alert, password_reset) bypass prefs entirely and
 *    are always delivered on all available channels.
 *  - notification_deliveries records EVERY attempt so we can debug "why didn't
 *    I get an email" without guessing.
 *  - broadcast_announcements stores the segment definition so we can re-run
 *    the exact same segment later (e.g. after failure).
 *  - device_tokens.unique on (user_id, token) — same device can't register twice.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── user_notification_prefs ─────────────────────────────
        Schema::create('user_notification_prefs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('event_key', 60);               // e.g. 'forum.reply', 'payment.received'
            $t->string('channel', 20);                  // 'email' | 'in_app' | 'whatsapp' | 'push' | 'sms'
            $t->boolean('enabled')->default(true);
            $t->timestamps();

            $t->unique(['user_id', 'event_key', 'channel'], 'unp_unique');
            $t->index(['user_id', 'enabled'], 'unp_user_enabled');
        });

        // ── notification_deliveries ─────────────────────────────
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('event_key', 60);
            $t->string('channel', 20);
            $t->string('subject', 200)->nullable();
            $t->text('preview')->nullable();            // First 200 chars of body for admin debug
            $t->json('payload')->nullable();            // Full event payload for retry
            $t->enum('status', ['queued', 'sent', 'failed', 'skipped'])->default('queued')->index();
            $t->string('skipped_reason', 100)->nullable(); // e.g. 'user_opted_out', 'rate_limited', 'inactive_user'
            $t->text('error_message')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamp('next_retry_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'created_at'], 'nd_user_time');
            $t->index(['event_key', 'channel', 'status'], 'nd_event_channel_status');
            $t->index(['status', 'next_retry_at'], 'nd_retry_scan');
        });

        // ── broadcast_announcements ─────────────────────────────
        Schema::create('broadcast_announcements', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $t->string('title', 200);
            $t->text('body');
            $t->json('segment');                        // {role, course_id, organization_id, etc.}
            $t->json('channels');                       // ['email', 'in_app']
            $t->unsignedInteger('audience_size')->default(0);   // computed at send time
            $t->unsignedInteger('sent_count')->default(0);
            $t->unsignedInteger('failed_count')->default(0);
            $t->enum('status', ['draft', 'sending', 'sent', 'failed'])->default('draft');
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();

            $t->index(['status', 'created_at']);
        });

        // ── device_tokens (mobile push infra — deferred) ────────
        Schema::create('device_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('token', 500);                   // FCM registration token
            $t->enum('platform', ['ios', 'android', 'web']);
            $t->string('app_version', 20)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();

            $t->unique(['user_id', 'token'], 'dt_user_token_unique');
            $t->index(['user_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('broadcast_announcements');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('user_notification_prefs');
    }
};
