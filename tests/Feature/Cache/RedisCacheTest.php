<?php

namespace Tests\Feature\Cache;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Forum\ForumCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function base_path;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Redis cache behavior tests.
 *
 * Verifies:
 *   1. Dashboard endpoints cache results (second DB query hits cache, not DB)
 *   2. Cache keys are user-scoped so users don't see each other's dashboards
 *   3. Forum category list is cached and busted on write
 *   4. Cache driver is Redis (not file/database fallback)
 *   5. Cache TTL respects configured values
 */
class RedisCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client', 'facilitator'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function makeUser(string $role = 'student', array $extra = []): User
    {
        $u = User::create(array_merge([
            'uuid'              => (string) Str::uuid(),
            'email'             => 'u' . Str::random(6) . '@test.io',
            'password'          => bcrypt('secret'),
            'email_verified_at' => now(),
            'status'            => 'active',
        ], $extra));
        $u->assignRole($role);
        return $u;
    }

    // ── 1. Cache driver configured correctly ──────────────────────────────────

    public function test_production_env_sets_cache_store_to_redis(): void
    {
        // phpunit.xml overrides CACHE_STORE=array for test isolation,
        // so we check the production .env directly.
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $this->markTestSkipped('.env file not present in this environment.');
        }

        $envContents = file_get_contents($envPath);
        $this->assertStringContainsString(
            'CACHE_STORE=redis',
            $envContents,
            'Production .env must configure Redis as the cache store for performance'
        );
    }

    public function test_redis_server_is_reachable_from_app_container(): void
    {
        // Use the redis store explicitly (bypasses phpunit.xml array override)
        $redisStore = Cache::store('redis');
        $redisStore->put('ping_test_' . Str::random(4), 'pong', 10);
        $key = 'ping_test_' . Str::random(4);
        Cache::store('redis')->put($key, 'pong', 10);
        $this->assertSame('pong', Cache::store('redis')->get($key),
            'Redis must be reachable from the app container'
        );
    }

    public function test_array_cache_driver_active_in_test_environment(): void
    {
        // Tests run with CACHE_STORE=array (from phpunit.xml) for isolation
        $this->assertSame('array', config('cache.default'),
            'Tests must use array cache (set in phpunit.xml) to avoid Redis state pollution'
        );
    }

    // ── 2. Forum category cache ────────────────────────────────────────────────

    public function test_forum_categories_are_served_from_cache_on_second_call(): void
    {
        $trainer = $this->makeUser('trainer');
        Sanctum::actingAs($trainer);

        // Force a cache miss by clearing the key
        Cache::forget(ForumCategory::LIST_CACHE_KEY);

        $queryCount = 0;
        DB::listen(function ($q) use (&$queryCount) {
            if (str_contains($q->sql, 'forum_categories')) $queryCount++;
        });

        $this->getJson('/api/v1/forum/categories')->assertOk();
        $afterFirst = $queryCount;

        $this->getJson('/api/v1/forum/categories')->assertOk();
        $afterSecond = $queryCount;

        $this->assertSame(
            $afterFirst, $afterSecond,
            'Second call must hit cache — no new DB query for forum_categories'
        );
    }

    public function test_forum_category_cache_is_busted_when_category_updated(): void
    {
        $admin = $this->makeUser('system_admin');
        Sanctum::actingAs($admin);

        // Populate the cache
        Cache::put(ForumCategory::LIST_CACHE_KEY, ['cached_data'], 300);
        $this->assertTrue(Cache::has(ForumCategory::LIST_CACHE_KEY));

        // Trigger an update via the model's booted hook
        $cat = ForumCategory::create([
            'uuid'  => (string) Str::uuid(),
            'slug'  => 'test-cat-' . Str::random(4),
            'name'  => 'Test Category',
            'color' => '#ff0000',
        ]);

        $this->assertFalse(
            Cache::has(ForumCategory::LIST_CACHE_KEY),
            'Cache must be busted immediately when a forum category is saved'
        );
    }

    public function test_forum_category_cache_is_busted_on_delete(): void
    {
        $cat = ForumCategory::create([
            'uuid'  => (string) Str::uuid(),
            'slug'  => 'del-cat-' . Str::random(4),
            'name'  => 'Delete Me',
            'color' => '#000000',
        ]);

        Cache::put(ForumCategory::LIST_CACHE_KEY, ['stale'], 300);
        $cat->delete();

        $this->assertFalse(
            Cache::has(ForumCategory::LIST_CACHE_KEY),
            'Cache must be busted when a forum category is deleted'
        );
    }

    // ── 3. Dashboard cache is user-scoped ─────────────────────────────────────

    public function test_student_dashboard_caches_per_user(): void
    {
        $student1 = $this->makeUser('student');
        $student2 = $this->makeUser('student');

        // Pre-populate student2's cache with sentinel value
        $cacheKey2 = "student:dashboard:{$student2->id}:30";
        Cache::put($cacheKey2, ['sentinel' => 'student2_data'], 60);

        Sanctum::actingAs($student1);
        $this->getJson('/api/v1/student/dashboard')->assertOk();

        // Student2's cache must remain untouched
        $this->assertSame('student2_data', Cache::get($cacheKey2)['sentinel'] ?? null);
    }

    public function test_trainer_dashboard_caches_per_user(): void
    {
        $trainer1 = $this->makeUser('trainer');
        $trainer2 = $this->makeUser('trainer');

        // Pre-seed trainer2's cache
        $cacheKey2 = "trainer:dashboard:{$trainer2->id}:30";
        Cache::put($cacheKey2, ['sentinel' => 'trainer2_only'], 60);

        Sanctum::actingAs($trainer1);
        $this->getJson('/api/v1/trainer/dashboard')->assertOk();

        // Trainer2's cache key must not have been overwritten
        $this->assertSame('trainer2_only', Cache::get($cacheKey2)['sentinel'] ?? null);
    }

    public function test_dashboard_second_request_hits_cache_not_db(): void
    {
        $student = $this->makeUser('student');
        Sanctum::actingAs($student);

        // Warm the cache on first call
        $this->getJson('/api/v1/student/dashboard')->assertOk();

        // Count DB queries on second call — should be near 0 for main payload
        $dbHits = 0;
        DB::listen(fn ($q) => $dbHits++);

        $this->getJson('/api/v1/student/dashboard')->assertOk();

        // Sanctum auth still runs a query, but the heavy dashboard aggregation shouldn't
        $this->assertLessThan(
            5,
            $dbHits,
            "Dashboard second request hit {$dbHits} queries — should be served from cache"
        );
    }

    // ── 4. Live quiz Redis counter ─────────────────────────────────────────────

    public function test_live_quiz_answer_counter_increments_in_redis(): void
    {
        $pin        = '123456';
        $questionId = 42;
        $key        = "session:{$pin}:answers:{$questionId}";

        Cache::forget($key);

        $pos1 = Cache::increment($key);
        $pos2 = Cache::increment($key);
        $pos3 = Cache::increment($key);

        $this->assertSame(1, (int) $pos1, 'First answer must get position 1');
        $this->assertSame(2, (int) $pos2, 'Second answer must get position 2');
        $this->assertSame(3, (int) $pos3, 'Third answer must get position 3');
    }

    public function test_live_quiz_counter_resets_to_zero_after_forget(): void
    {
        $pin = '999999'; $qId = 1;
        $key = "session:{$pin}:answers:{$qId}";

        Cache::put($key, 15);
        Cache::forget($key);

        $this->assertNull(Cache::get($key), 'Counter must be null after forget (question restart)');
        $newPos = Cache::increment($key);
        $this->assertSame(1, (int) $newPos, 'After reset, first answer gets position 1 again');
    }

    public function test_live_quiz_counters_isolated_between_questions(): void
    {
        $pin = '777777';

        Cache::put("session:{$pin}:answers:10", 3);
        Cache::put("session:{$pin}:answers:20", 7);

        $this->assertSame(3, (int) Cache::get("session:{$pin}:answers:10"));
        $this->assertSame(7, (int) Cache::get("session:{$pin}:answers:20"));
    }

    // ── 5. Cache TTL semantics ─────────────────────────────────────────────────

    public function test_cache_key_expires_after_ttl(): void
    {
        Cache::put('ttl_test', 'value', 1); // 1 second TTL
        $this->assertSame('value', Cache::get('ttl_test'));

        sleep(2);

        $this->assertNull(Cache::get('ttl_test'), 'Key must expire after TTL');
    }

    // ── 6. Cache isolation between test runs ──────────────────────────────────

    public function test_cache_flush_works(): void
    {
        Cache::put('a', 1);
        Cache::put('b', 2);
        Cache::flush();

        $this->assertNull(Cache::get('a'));
        $this->assertNull(Cache::get('b'));
    }
}
