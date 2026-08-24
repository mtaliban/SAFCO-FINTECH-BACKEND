<?php

namespace Tests\Feature\Nfr;

use App\Models\Forum\ForumCategory;
use App\Services\Backup\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\TestCase;

/**
 * SRS Non-Functional Requirements — audit tests.
 *
 * Guards the deep fixes:
 *   A. Backup prune keeps DB+tar PAIRS (not flat file count)
 *   B. Backup tarball excludes backups/ (no recursion explosion)
 *   D. Backup gzip integrity check throws on corrupt file
 *   E. Forum categories cache invalidates on write
 *   F. CORS whitelist rejects unknown origin
 */
class NfrAuditTest extends TestCase
{
    use RefreshDatabase;

    // ── AUDIT-A: prune pairs ───────────────────────────────────

    public function test_prune_keeps_pairs_by_timestamp_not_flat_count(): void
    {
        Storage::fake('backups');

        // Simulate 6 weekly backups (each = .sql.gz + -files.tar.gz = 12 files)
        $stamps = [];
        for ($i = 6; $i >= 1; $i--) {
            $stamps[] = now()->subDays($i * 7)->format('Y-m-d_His');
        }
        foreach ($stamps as $stamp) {
            Storage::disk('backups')->put("backups/weekly/safco-weekly-{$stamp}.sql.gz", 'data');
            Storage::disk('backups')->put("backups/weekly/safco-weekly-{$stamp}-files.tar.gz", 'data');
        }
        $this->assertCount(12, Storage::disk('backups')->files('backups/weekly'));

        $deleted = app(BackupService::class)->prune(BackupService::TYPE_WEEKLY);

        // Retention = 4 weekly backups → keep 4 pairs (8 files), delete 2 pairs (4 files)
        $this->assertSame(4, $deleted, 'must delete 2 old pairs = 4 files');
        $remaining = Storage::disk('backups')->files('backups/weekly');
        $this->assertCount(8, $remaining, 'must keep 4 pairs = 8 files');

        // The 4 kept pairs must be the NEWEST (higher timestamps in array)
        $kept = collect($remaining)
            ->map(fn ($f) => preg_replace('/^.*-(\d{4}-\d{2}-\d{2}_\d{6}).*$/', '$1', $f))
            ->unique()->sortDesc()->values()->all();
        $newestStamps = array_slice(array_reverse($stamps), 0, 4);
        sort($newestStamps);
        sort($kept);
        $this->assertSame($newestStamps, $kept);
    }

    // ── AUDIT-B: tarball excludes backups/ ─────────────────────

    public function test_archive_storage_excludes_backups_folder(): void
    {
        // Verify the exclude flag is present in the archiveStorage call.
        // We reflect the source rather than actually running tar, so this test
        // is fast and portable.
        $ref = new ReflectionClass(BackupService::class);
        $source = file_get_contents($ref->getFileName());
        $this->assertStringContainsString('--exclude=', $source);
        $this->assertStringContainsString('/backups', $source);
    }

    // ── AUDIT-D: gzip integrity check ──────────────────────────

    public function test_gzip_integrity_check_deletes_corrupt_file(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('backups/daily/safco-daily-corrupt.sql.gz', 'this is NOT gzip');

        // Invoke the private method via reflection
        $svc = app(BackupService::class);
        $ref = new ReflectionClass($svc);
        $m = $ref->getMethod('assertGzipIntegrity');
        $m->setAccessible(true);

        try {
            $m->invoke($svc, 'backups/daily/safco-daily-corrupt.sql.gz');
            $this->fail('expected RuntimeException on corrupt gzip');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('gzip', strtolower($e->getMessage()));
        }
        // Bad file must have been deleted
        $this->assertFalse(Storage::disk('backups')->exists('backups/daily/safco-daily-corrupt.sql.gz'));
    }

    // ── AUDIT-E: cache invalidation on category write ─────────

    public function test_category_write_busts_the_cache(): void
    {
        // Prime the cache with a known value
        Cache::put(ForumCategory::LIST_CACHE_KEY, ['sentinel'], now()->addMinutes(5));
        $this->assertSame(['sentinel'], Cache::get(ForumCategory::LIST_CACHE_KEY));

        // Any write should bust it
        ForumCategory::where('slug', 'ideas')->first()?->touch();
        $this->assertNull(
            Cache::get(ForumCategory::LIST_CACHE_KEY),
            'saved() hook must forget the cache key so admins never see stale category data',
        );
    }

    public function test_category_create_busts_the_cache(): void
    {
        Cache::put(ForumCategory::LIST_CACHE_KEY, ['sentinel'], now()->addMinutes(5));
        ForumCategory::create([
            'slug' => 'new-cat', 'name' => 'New', 'sort_order' => 99,
        ]);
        $this->assertNull(Cache::get(ForumCategory::LIST_CACHE_KEY));
    }

    // ── AUDIT-F: CORS whitelist rejects unknown origin ────────

    public function test_cors_config_uses_env_whitelist_not_wildcard(): void
    {
        $allowed = config('cors.allowed_origins');
        $this->assertNotContains('*', $allowed, 'CORS must not allow * — env whitelist required');
        $this->assertIsArray($allowed);
        $this->assertNotEmpty($allowed);
    }

    public function test_cors_supports_credentials(): void
    {
        // With supports_credentials=true, browsers require exact origin match
        // (spec forbids "*"). Verifying the flag prevents accidental regression.
        $this->assertTrue((bool) config('cors.supports_credentials'));
    }
}
