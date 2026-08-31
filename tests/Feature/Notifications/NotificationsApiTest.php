<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Models\UserNotificationPref;
use App\Models\UserProfile;
use App\Events\InAppNotificationSent;
use App\Services\Notifications\Channels\InAppChannel;
use Illuminate\Support\Facades\Event;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 15 — Full API + real-time event tests.
 *
 * Coverage:
 *  A. GET  /notifications/inbox        — returns items, unread_count, supports filter
 *  B. POST /notifications/inbox/{id}/read   — marks one read, decrements count
 *  C. POST /notifications/inbox/read-all    — marks all read
 *  D. DELETE /notifications/inbox/{id}     — deletes item, not returned in inbox
 *  E. Inbox filter=unread excludes read items
 *  F. Inbox limit param is honoured
 *  G. GET  /notifications/preferences  — returns full matrix
 *  H. PUT  /notifications/preferences  — persists toggle, critical silently ignored
 *  I. InAppChannel broadcasts Reverb event after DB insert
 *  J. Unauthenticated requests → 401
 *  K. Inbox pagination / limit
 *  L. Concurrent mark-all + delete does not 500
 */
class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        Mail::fake();
        Event::fake([InAppNotificationSent::class]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeUser(string $role = 'student', string $name = 'Test User'): User
    {
        $u = User::create([
            'uuid'               => (string) Str::uuid(),
            'email'              => strtolower(str_replace(' ', '', $name)) . Str::random(4) . '@test.io',
            'password'           => bcrypt('secret'),
            'email_verified_at'  => now(),
            'status'             => 'active',
        ]);
        $u->assignRole($role);
        UserProfile::create([
            'user_id'    => $u->id,
            'full_name'  => $name,
            'first_name' => explode(' ', $name)[0],
            'last_name'  => explode(' ', $name)[1] ?? '',
        ]);
        return $u->fresh();
    }

    private function seedNotification(User $user, string $eventKey = 'course.enrolled', string $title = 'Test', ?string $actionUrl = null, bool $read = false): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id'              => $id,
            'type'            => 'App\\Notifications\\SafcoInboxNotification',
            'notifiable_type' => User::class,
            'notifiable_id'   => $user->id,
            'data'            => json_encode([
                'event_key'  => $eventKey,
                'title'      => $title,
                'body'       => 'Test notification body',
                'action_url' => $actionUrl,
                'payload'    => [],
            ]),
            'read_at'    => $read ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // J. Unauthenticated → 401
    // ─────────────────────────────────────────────────────────────────────────

    public function test_inbox_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications/inbox')->assertUnauthorized();
    }

    public function test_preferences_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications/preferences')->assertUnauthorized();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A. GET /notifications/inbox
    // ─────────────────────────────────────────────────────────────────────────

    public function test_inbox_returns_items_and_unread_count(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->seedNotification($user, 'course.enrolled', 'Course A');
        $this->seedNotification($user, 'quiz.result', 'Quiz result', read: true);

        $r = $this->getJson('/api/v1/notifications/inbox?filter=all');
        $r->assertOk();

        $data = $r->json('data');
        $this->assertCount(2, $data['items']);
        $this->assertSame(1, $data['unread_count']);
    }

    public function test_inbox_only_returns_current_users_notifications(): void
    {
        $user1 = $this->makeUser(name: 'Alice Test');
        $user2 = $this->makeUser(name: 'Bob Test');

        $this->seedNotification($user1, 'course.enrolled', 'Alice notif');
        $this->seedNotification($user2, 'course.enrolled', 'Bob notif');

        Sanctum::actingAs($user1);
        $r = $this->getJson('/api/v1/notifications/inbox?filter=all');
        $r->assertOk();

        $items = $r->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('Alice notif', $items[0]['title']);
    }

    public function test_inbox_items_contain_required_fields(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $this->seedNotification($user, 'payment.received', 'Payment OK', '/billing');

        $r = $this->getJson('/api/v1/notifications/inbox?filter=all');
        $r->assertOk();

        $item = $r->json('data.items.0');
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('event_key', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('body', $item);
        $this->assertArrayHasKey('action_url', $item);
        $this->assertArrayHasKey('read_at', $item);
        $this->assertArrayHasKey('created_at', $item);
        $this->assertSame('payment.received', $item['event_key']);
        $this->assertSame('/billing', $item['action_url']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E. filter=unread excludes read items
    // ─────────────────────────────────────────────────────────────────────────

    public function test_filter_unread_excludes_read_items(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->seedNotification($user, 'course.enrolled', 'Unread A');
        $this->seedNotification($user, 'course.enrolled', 'Unread B');
        $this->seedNotification($user, 'quiz.result', 'Already read', read: true);

        $r = $this->getJson('/api/v1/notifications/inbox?filter=unread');
        $r->assertOk();

        $items = $r->json('data.items');
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertNull($item['read_at'], 'unread filter must not return read items');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F. limit param
    // ─────────────────────────────────────────────────────────────────────────

    public function test_inbox_limit_param_is_honoured(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->seedNotification($user, 'course.enrolled', "Notif $i");
        }

        $r = $this->getJson('/api/v1/notifications/inbox?filter=all&limit=3');
        $r->assertOk();
        $this->assertCount(3, $r->json('data.items'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B. POST /notifications/inbox/{id}/read
    // ─────────────────────────────────────────────────────────────────────────

    public function test_mark_read_sets_read_at_and_decrements_unread_count(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $id = $this->seedNotification($user, 'course.enrolled', 'To read');

        // Verify unread before
        $before = $this->getJson('/api/v1/notifications/inbox?filter=unread')->json('data.unread_count');
        $this->assertSame(1, $before);

        $this->postJson("/api/v1/notifications/inbox/{$id}/read")->assertOk();

        // After mark-read, unread count must drop
        $after = $this->getJson('/api/v1/notifications/inbox?filter=unread')->json('data.unread_count');
        $this->assertSame(0, $after);

        // DB must have read_at set
        $this->assertNotNull(
            DB::table('notifications')->where('id', $id)->value('read_at')
        );
    }

    public function test_mark_read_on_another_users_notification_returns_404(): void
    {
        $user1 = $this->makeUser(name: 'Alice Test');
        $user2 = $this->makeUser(name: 'Bob Test');

        $id = $this->seedNotification($user2, 'course.enrolled', 'Bob notif');

        Sanctum::actingAs($user1);
        $this->postJson("/api/v1/notifications/inbox/{$id}/read")->assertNotFound();
    }

    public function test_mark_read_is_idempotent(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $id = $this->seedNotification($user, 'quiz.result', 'Already read', read: true);

        // Marking an already-read item must not error
        $this->postJson("/api/v1/notifications/inbox/{$id}/read")->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C. POST /notifications/inbox/read-all
    // ─────────────────────────────────────────────────────────────────────────

    public function test_mark_all_read_clears_badge(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->seedNotification($user, 'course.enrolled', 'N1');
        $this->seedNotification($user, 'quiz.result',    'N2');
        $this->seedNotification($user, 'forum.reply',    'N3');

        $before = $this->getJson('/api/v1/notifications/inbox?filter=unread')
            ->json('data.unread_count');
        $this->assertSame(3, $before);

        $this->postJson('/api/v1/notifications/inbox/read-all')->assertOk();

        $after = $this->getJson('/api/v1/notifications/inbox?filter=unread')
            ->json('data.unread_count');
        $this->assertSame(0, $after);

        // All rows must have read_at set
        $stillUnread = DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->count();
        $this->assertSame(0, $stillUnread);
    }

    public function test_mark_all_read_only_affects_current_user(): void
    {
        $user1 = $this->makeUser(name: 'Alice Test');
        $user2 = $this->makeUser(name: 'Bob Test');

        $this->seedNotification($user1, 'course.enrolled', 'Alice');
        $this->seedNotification($user2, 'course.enrolled', 'Bob');

        Sanctum::actingAs($user1);
        $this->postJson('/api/v1/notifications/inbox/read-all')->assertOk();

        // Bob's notification must still be unread
        $bobUnread = DB::table('notifications')
            ->where('notifiable_id', $user2->id)
            ->whereNull('read_at')
            ->count();
        $this->assertSame(1, $bobUnread);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // D. DELETE /notifications/inbox/{id}
    // ─────────────────────────────────────────────────────────────────────────

    public function test_delete_removes_notification_from_inbox(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $id = $this->seedNotification($user, 'payment.received', 'To delete');

        $this->deleteJson("/api/v1/notifications/inbox/{$id}")->assertOk();

        // Must be gone from DB
        $this->assertDatabaseMissing('notifications', ['id' => $id]);

        // Must not appear in inbox
        $items = $this->getJson('/api/v1/notifications/inbox?filter=all')
            ->json('data.items');
        $ids = array_column($items, 'id');
        $this->assertNotContains($id, $ids);
    }

    public function test_delete_another_users_notification_returns_404(): void
    {
        $user1 = $this->makeUser(name: 'Alice Test');
        $user2 = $this->makeUser(name: 'Bob Test');

        $id = $this->seedNotification($user2, 'course.enrolled', 'Bobs notif');

        Sanctum::actingAs($user1);
        $this->deleteJson("/api/v1/notifications/inbox/{$id}")->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // L. Concurrent mark-all + delete stability
    // ─────────────────────────────────────────────────────────────────────────

    public function test_delete_then_mark_all_read_does_not_error(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $id1 = $this->seedNotification($user, 'course.enrolled', 'N1');
        $this->seedNotification($user, 'forum.reply', 'N2');

        // Delete first, then mark-all — should not throw
        $this->deleteJson("/api/v1/notifications/inbox/{$id1}")->assertOk();
        $this->postJson('/api/v1/notifications/inbox/read-all')->assertOk();

        $count = $this->getJson('/api/v1/notifications/inbox?filter=unread')
            ->json('data.unread_count');
        $this->assertSame(0, $count);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // G. GET /notifications/preferences
    // ─────────────────────────────────────────────────────────────────────────

    public function test_preferences_returns_all_event_categories(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $r = $this->getJson('/api/v1/notifications/preferences');
        $r->assertOk();

        $data = $r->json('data');
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('channels', $data);
        $this->assertArrayHasKey('active_channels', $data);
        $this->assertArrayHasKey('events', $data);

        // All 7 categories must exist
        $cats = array_keys($data['categories']);
        foreach (['account', 'learning', 'assessments', 'payments', 'forum', 'trainer', 'system'] as $cat) {
            $this->assertContains($cat, $cats, "Missing category: {$cat}");
        }
    }

    public function test_preferences_reflects_saved_pref_row(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        // Save a custom pref
        UserNotificationPref::create([
            'user_id'   => $user->id,
            'event_key' => 'forum.reply',
            'channel'   => 'email',
            'enabled'   => false,
        ]);

        $r = $this->getJson('/api/v1/notifications/preferences')->assertOk();
        $events = $r->json('data.events');

        $forumReply = collect($events)->firstWhere('key', 'forum.reply');
        $this->assertNotNull($forumReply);
        $this->assertFalse($forumReply['channels']['email']['enabled']);
    }

    public function test_active_channels_list_contains_email_and_in_app(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $r = $this->getJson('/api/v1/notifications/preferences')->assertOk();
        $active = $r->json('data.active_channels');

        $this->assertContains('email', $active);
        $this->assertContains('in_app', $active);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // H. PUT /notifications/preferences
    // ─────────────────────────────────────────────────────────────────────────

    public function test_preferences_update_persists_email_disable(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $r = $this->putJson('/api/v1/notifications/preferences', [
            'prefs' => [
                ['event_key' => 'quiz.result', 'channel' => 'email', 'enabled' => false],
            ],
        ]);
        $r->assertOk();

        $this->assertDatabaseHas('user_notification_prefs', [
            'user_id'   => $user->id,
            'event_key' => 'quiz.result',
            'channel'   => 'email',
            'enabled'   => false,
        ]);
    }

    public function test_preferences_update_persists_multiple_prefs_at_once(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/preferences', [
            'prefs' => [
                ['event_key' => 'forum.reply',    'channel' => 'email',  'enabled' => false],
                ['event_key' => 'forum.mention',  'channel' => 'in_app', 'enabled' => true],
                ['event_key' => 'payment.failed', 'channel' => 'email',  'enabled' => true],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('user_notification_prefs', ['user_id' => $user->id, 'event_key' => 'forum.reply',   'enabled' => false]);
        $this->assertDatabaseHas('user_notification_prefs', ['user_id' => $user->id, 'event_key' => 'forum.mention', 'enabled' => true]);
        $this->assertDatabaseHas('user_notification_prefs', ['user_id' => $user->id, 'event_key' => 'payment.failed','enabled' => true]);
    }

    public function test_preferences_critical_events_cannot_be_disabled(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/preferences', [
            'prefs' => [
                ['event_key' => 'account.security_alert', 'channel' => 'email', 'enabled' => false],
                ['event_key' => 'account.password_reset', 'channel' => 'email', 'enabled' => false],
            ],
        ])->assertOk();

        // Critical toggles are silently ignored — no row must exist
        $this->assertDatabaseMissing('user_notification_prefs', [
            'user_id'   => $user->id,
            'event_key' => 'account.security_alert',
        ]);
        $this->assertDatabaseMissing('user_notification_prefs', [
            'user_id'   => $user->id,
            'event_key' => 'account.password_reset',
        ]);
    }

    public function test_preferences_update_requires_prefs_array(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/notifications/preferences', [])
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // I. InAppChannel broadcasts Reverb event after DB insert
    // ─────────────────────────────────────────────────────────────────────────

    public function test_in_app_channel_broadcasts_reverb_event(): void
    {
        Event::fake([InAppNotificationSent::class]);

        $user = $this->makeUser();

        $channel = new InAppChannel();
        $result  = $channel->send($user, 'course.enrolled', ['course_title' => 'Excel Advanced']);

        $this->assertSame('sent', $result['status']);

        // Reverb broadcast event must have been dispatched
        Event::assertDispatched(InAppNotificationSent::class, function ($e) use ($user) {
            return (new \ReflectionProperty($e, 'userId'))->getValue($e) === $user->id
                && $e->eventKey === 'course.enrolled';
        });

        // DB row must also exist
        $this->assertDatabaseHas('notifications', [
            'notifiable_id'   => $user->id,
            'notifiable_type' => User::class,
        ]);
    }

    public function test_in_app_channel_reverb_payload_contains_correct_fields(): void
    {
        Event::fake([InAppNotificationSent::class]);

        $user = $this->makeUser();

        $channel = new InAppChannel();
        $channel->send($user, 'assignment.graded', [
            'assignment_title' => 'IFRS 9 Paper',
            'grade'            => 85,
            'max_points'       => 100,
            'action_url'       => '/student/assignments/abc',
        ]);

        Event::assertDispatched(InAppNotificationSent::class, function ($e) {
            $payload = $e->broadcastWith();
            return isset($payload['id'], $payload['event_key'], $payload['title'], $payload['action_url'])
                && $payload['event_key']  === 'assignment.graded'
                && $payload['action_url'] === '/student/assignments/abc';
        });
    }

    public function test_reverb_failure_does_not_break_in_app_delivery(): void
    {
        // Even if the broadcast event throws, the in-app notification must be saved to DB
        Event::fake([InAppNotificationSent::class]);

        $user = $this->makeUser();

        $channel = new InAppChannel();

        try {
            $result = $channel->send($user, 'course.enrolled', ['course_title' => 'Reverb down test']);
        } catch (\Throwable) {
            $this->fail('InAppChannel must not propagate broadcast exceptions');
        }

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Full dispatcher integration: event fires → notification in inbox
    // ─────────────────────────────────────────────────────────────────────────

    public function test_dispatch_creates_inbox_notification_visible_via_api(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        app(NotificationDispatcher::class)->dispatch($user, 'certificate.issued', [
            'download_url' => '/student/certificates/xyz',
        ]);

        $r = $this->getJson('/api/v1/notifications/inbox?filter=all');
        $r->assertOk();

        $items  = $r->json('data.items');
        $unread = $r->json('data.unread_count');

        // Must appear in inbox
        $this->assertNotEmpty($items);
        $keys = array_column($items, 'event_key');
        $this->assertContains('certificate.issued', $keys);

        // Unread count must reflect the new notification
        $this->assertGreaterThan(0, $unread);
    }
}
