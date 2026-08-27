<?php

namespace Tests\Feature\Notifications;

use App\Models\BroadcastAnnouncement;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\UserNotificationPref;
use App\Models\UserProfile;
use App\Services\Notifications\BroadcastService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 15 — Feature tests for the central notification dispatcher.
 *
 * Coverage:
 *  A. Dispatch fires on all default channels for a fresh user
 *  B. User pref = disabled → channel not attempted (no delivery row)
 *  C. Critical events bypass user prefs (always sent)
 *  D. Inactive users receive nothing (single skipped row)
 *  E. Rate limit blocks 7th send in same hour
 *  F. Delivery row records subject + preview on success
 *  G. WhatsApp/push channels record as skipped/deferred
 *  H. Preferences API returns full matrix + honours saved rows
 *  I. Admin broadcast fans out to segment + logs deliveries
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        Mail::fake(); // don't hit Gmail during tests
    }

    private function makeUser(string $role = 'student', string $status = 'active', string $name = 'User Name'): User
    {
        $u = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => strtolower(explode(' ', $name)[0]) . Str::random(6) . '@t.io',
            'password' => bcrypt('x'),
            'email_verified_at' => now(),
            'status' => $status,
        ]);
        $u->assignRole($role);
        UserProfile::create([
            'user_id' => $u->id,
            'full_name' => $name,
            'first_name' => explode(' ', $name)[0],
            'last_name' => explode(' ', $name)[1] ?? '',
        ]);
        return $u->fresh();
    }

    // ── A: default channels fire ─────────────────────────────────

    public function test_dispatch_fires_default_channels(): void
    {
        $u = $this->makeUser();
        app(NotificationDispatcher::class)->dispatch($u, 'course.enrolled', [
            'course_title' => 'Excel Basics',
        ]);

        // course.enrolled defaults to [email, in_app]
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $u->id, 'event_key' => 'course.enrolled',
            'channel' => 'email', 'status' => 'sent',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $u->id, 'event_key' => 'course.enrolled',
            'channel' => 'in_app', 'status' => 'sent',
        ]);
    }

    // ── B: user pref disabled = channel skipped ─────────────────

    public function test_user_opt_out_prevents_email_send(): void
    {
        $u = $this->makeUser();
        UserNotificationPref::create([
            'user_id' => $u->id,
            'event_key' => 'course.enrolled',
            'channel' => 'email',
            'enabled' => false,
        ]);

        app(NotificationDispatcher::class)->dispatch($u, 'course.enrolled', ['course_title' => 'X']);

        // Email must NOT have a delivery row (silent skip)
        $this->assertDatabaseMissing('notification_deliveries', [
            'user_id' => $u->id, 'event_key' => 'course.enrolled', 'channel' => 'email',
        ]);
        // In-app still fires
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $u->id, 'event_key' => 'course.enrolled',
            'channel' => 'in_app', 'status' => 'sent',
        ]);
    }

    // ── C: critical events bypass prefs ─────────────────────────

    public function test_critical_event_ignores_user_opt_out(): void
    {
        $u = $this->makeUser();
        UserNotificationPref::create([
            'user_id' => $u->id,
            'event_key' => 'account.security_alert',
            'channel' => 'email',
            'enabled' => false,   // user tries to opt out
        ]);

        app(NotificationDispatcher::class)->dispatch($u, 'account.security_alert', [
            'event' => 'New device sign-in',
            'ip' => '1.2.3.4',
        ]);

        // Security alerts always send regardless of prefs
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $u->id,
            'event_key' => 'account.security_alert',
            'channel' => 'email',
            'status' => 'sent',
        ]);
    }

    // ── D: inactive users get nothing ────────────────────────────

    public function test_inactive_user_receives_nothing(): void
    {
        $u = $this->makeUser('student', 'suspended');
        app(NotificationDispatcher::class)->dispatch($u, 'course.enrolled', ['course_title' => 'X']);

        $sent = NotificationDelivery::where('user_id', $u->id)->where('status', 'sent')->count();
        $skipped = NotificationDelivery::where('user_id', $u->id)->where('status', 'skipped')->count();

        $this->assertSame(0, $sent, 'suspended users must not receive any sent delivery');
        $this->assertSame(1, $skipped, 'exactly one skipped row logged for the entire event');
    }

    // ── E: rate limit ───────────────────────────────────────────

    public function test_rate_limit_blocks_after_max_per_hour(): void
    {
        $u = $this->makeUser();
        $d = app(NotificationDispatcher::class);

        // First 6 send OK; the 7th should be rate-limited.
        for ($i = 0; $i < 6; $i++) {
            $d->dispatch($u, 'course.enrolled', ['course_title' => "Course $i"]);
        }
        $d->dispatch($u, 'course.enrolled', ['course_title' => 'Overflow']);

        $emailSent = NotificationDelivery::where('user_id', $u->id)
            ->where('event_key', 'course.enrolled')
            ->where('channel', 'email')
            ->where('status', 'sent')->count();
        $emailBlocked = NotificationDelivery::where('user_id', $u->id)
            ->where('event_key', 'course.enrolled')
            ->where('channel', 'email')
            ->where('status', 'skipped')
            ->where('skipped_reason', 'rate_limited')->count();

        $this->assertSame(6, $emailSent);
        $this->assertSame(1, $emailBlocked);
    }

    // ── F: delivery row captures preview ────────────────────────

    public function test_delivery_row_captures_subject_and_preview(): void
    {
        $u = $this->makeUser(name: 'Amina Test');
        app(NotificationDispatcher::class)->dispatch($u, 'payment.received', [
            'amount' => 50000,
            'description' => 'Excel course',
        ]);
        $d = NotificationDelivery::where('user_id', $u->id)
            ->where('channel', 'email')->first();
        $this->assertNotNull($d);
        $this->assertStringContainsString('50,000', $d->subject);
        $this->assertStringContainsString('50,000', $d->preview);
    }

    // ── G: WhatsApp/push record as skipped/deferred ─────────────

    public function test_whatsapp_channel_skipped_as_deferred(): void
    {
        // Force whatsapp active in a hacky way — the dispatcher's
        // resolveChannels only iterates activeChannels(). To test the
        // WhatsAppChannel directly, invoke it.
        $u = $this->makeUser();
        $result = app(\App\Services\Notifications\Channels\WhatsAppChannel::class)
            ->send($u, 'course.enrolled', []);
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('whatsapp_deferred', $result['reason']);
    }

    public function test_push_channel_skipped_when_no_devices(): void
    {
        $u = $this->makeUser();
        $result = app(\App\Services\Notifications\Channels\PushChannel::class)
            ->send($u, 'course.enrolled', []);
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('no_device_tokens', $result['reason']);
    }

    // ── H: Preferences API ──────────────────────────────────────

    public function test_preferences_index_returns_matrix_with_defaults(): void
    {
        $u = $this->makeUser();
        Sanctum::actingAs($u);
        $r = $this->getJson('/api/v1/notifications/preferences');
        $r->assertOk();

        $events = $r->json('data.events');
        $this->assertNotEmpty($events);
        // Every event must expose all channels
        foreach ($events as $e) {
            $this->assertArrayHasKey('email', $e['channels']);
            $this->assertArrayHasKey('in_app', $e['channels']);
        }
        // Critical events are locked = true
        $secAlert = collect($events)->firstWhere('key', 'account.security_alert');
        $this->assertTrue($secAlert['critical']);
        $this->assertTrue($secAlert['channels']['email']['locked']);
    }

    public function test_preferences_update_persists_toggle(): void
    {
        $u = $this->makeUser();
        Sanctum::actingAs($u);
        $r = $this->putJson('/api/v1/notifications/preferences', [
            'prefs' => [
                ['event_key' => 'forum.reply', 'channel' => 'email', 'enabled' => false],
            ],
        ]);
        $r->assertOk();
        $this->assertDatabaseHas('user_notification_prefs', [
            'user_id' => $u->id,
            'event_key' => 'forum.reply',
            'channel' => 'email',
            'enabled' => false,
        ]);
    }

    public function test_preferences_update_ignores_critical_disable(): void
    {
        $u = $this->makeUser();
        Sanctum::actingAs($u);
        $this->putJson('/api/v1/notifications/preferences', [
            'prefs' => [
                ['event_key' => 'account.security_alert', 'channel' => 'email', 'enabled' => false],
            ],
        ])->assertOk();
        // Row must NOT exist — critical toggle silently ignored
        $this->assertDatabaseMissing('user_notification_prefs', [
            'user_id' => $u->id,
            'event_key' => 'account.security_alert',
            'channel' => 'email',
        ]);
    }

    // ── I: Admin broadcast fan-out ─────────────────────────────

    public function test_broadcast_fans_out_to_role_segment(): void
    {
        $admin = $this->makeUser('system_admin');
        $s1 = $this->makeUser('student', name: 'Stu One');
        $s2 = $this->makeUser('student', name: 'Stu Two');
        $tr = $this->makeUser('trainer', name: 'Trainer T');

        $b = BroadcastAnnouncement::create([
            'created_by' => $admin->id,
            'title' => 'System update tonight',
            'body' => 'Maintenance from 10pm to midnight',
            'segment' => ['role' => 'student'],
            'channels' => ['email', 'in_app'],
            'status' => 'draft',
        ]);
        $b = app(BroadcastService::class)->send($b);

        $this->assertSame(2, $b->audience_size, 'segment should resolve to 2 students only');
        $this->assertGreaterThan(0, $b->sent_count);
        $this->assertSame('sent', $b->status);

        // Students received; trainer did not.
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $s1->id, 'event_key' => 'system.announcement', 'status' => 'sent',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $s2->id, 'event_key' => 'system.announcement', 'status' => 'sent',
        ]);
        $this->assertDatabaseMissing('notification_deliveries', [
            'user_id' => $tr->id, 'event_key' => 'system.announcement',
        ]);
    }

    public function test_broadcast_endpoint_admin_only(): void
    {
        $student = $this->makeUser('student');
        Sanctum::actingAs($student);
        $r = $this->postJson('/api/v1/admin/announcements', [
            'title' => 'test',
            'body' => 'test body',
        ]);
        $r->assertStatus(403);
    }

    public function test_broadcast_endpoint_admin_can_send(): void
    {
        $admin = $this->makeUser('system_admin');
        $this->makeUser('student');  // one recipient
        Sanctum::actingAs($admin);

        $r = $this->postJson('/api/v1/admin/announcements', [
            'title' => 'Habari za asubuhi',
            'body' => 'Karibuni kwenye sesheni ya kesho asubuhi.',
            'segment' => ['role' => 'student'],
        ]);
        $r->assertStatus(201);
        $this->assertSame(1, $r->json('data.audience_size'));
    }
}
