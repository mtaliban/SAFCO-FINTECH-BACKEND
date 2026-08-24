<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * SRS Non-Functional Requirements — Backup service.
 *
 * Backup types:
 *  - daily:   compressed DB dump (SQL) — small, fast, high frequency
 *  - weekly:  compressed DB dump + storage/app files tarball
 *  - monthly: same as weekly, kept in a separate 'archive/' folder for
 *             long-term retention (12 months)
 *
 * Retention (default):
 *   daily     → keep last 7
 *   weekly    → keep last 4
 *   monthly   → keep last 12
 *
 * Storage: writes to the 'backups' disk, which defaults to local storage
 * under storage/app/backups. Swap the disk to s3 in config/filesystems.php
 * (with AWS creds) to send backups off-server — this class doesn't care.
 *
 * Dump strategy:
 *   1. Try mysqldump (fast, standards-compliant)
 *   2. Fallback to pure-PHP dumper (works when mysqldump isn't installed)
 */
class BackupService
{
    public const TYPE_DAILY = 'daily';
    public const TYPE_WEEKLY = 'weekly';
    public const TYPE_MONTHLY = 'monthly';

    private const RETAIN = [
        self::TYPE_DAILY => 7,
        self::TYPE_WEEKLY => 4,
        self::TYPE_MONTHLY => 12,
    ];

    public function run(string $type): array
    {
        if (!in_array($type, [self::TYPE_DAILY, self::TYPE_WEEKLY, self::TYPE_MONTHLY], true)) {
            throw new \InvalidArgumentException("Unknown backup type: {$type}");
        }
        $stamp = now()->format('Y-m-d_His');
        $folder = $type === self::TYPE_MONTHLY ? 'backups/archive' : "backups/{$type}";
        $baseName = "safco-{$type}-{$stamp}";

        // 1) Dump database → gzipped SQL
        $sqlPath = $this->dumpDatabase("{$folder}/{$baseName}.sql.gz");
        $this->assertGzipIntegrity($sqlPath);
        $sizeSql = Storage::disk('backups')->size($sqlPath);

        $result = [
            'type' => $type,
            'db_file' => $sqlPath,
            'db_size' => $sizeSql,
            'files_file' => null,
            'files_size' => 0,
        ];

        // 2) For weekly/monthly, also tarball the private storage tree
        if ($type !== self::TYPE_DAILY) {
            $filesPath = $this->archiveStorage("{$folder}/{$baseName}-files.tar.gz");
            if ($filesPath) {
                $this->assertGzipIntegrity($filesPath);
                $result['files_file'] = $filesPath;
                $result['files_size'] = Storage::disk('backups')->size($filesPath);
            }
        }

        // 3) Prune old backups per retention policy
        $result['pruned'] = $this->prune($type);

        Log::info('[backup] complete', $result);
        return $result;
    }

    private function dumpDatabase(string $targetPath): string
    {
        $cfg = config('database.connections.' . config('database.default'));
        $mysqldump = trim((string) @shell_exec('command -v mysqldump'));

        Storage::disk('backups')->makeDirectory(dirname($targetPath));
        $absoluteTarget = Storage::disk('backups')->path($targetPath);

        if ($mysqldump !== '') {
            // mysqldump path — fast + reliable. Use a temp defaults file so the
            // password never appears on the command line (leaks to `ps auxww`).
            $defaults = tempnam(sys_get_temp_dir(), 'my');
            file_put_contents($defaults, "[client]\nuser={$cfg['username']}\npassword={$cfg['password']}\nhost={$cfg['host']}\nport={$cfg['port']}\n");
            @chmod($defaults, 0600);

            try {
                $cmd = [
                    $mysqldump,
                    "--defaults-extra-file={$defaults}",
                    '--single-transaction',
                    '--no-tablespaces',
                    '--routines',
                    '--triggers',
                    '--set-gtid-purged=OFF',
                    $cfg['database'],
                ];
                $dump = new Process($cmd, null, null, null, 900);
                $gzip = new Process(['gzip', '-c'], null, null, null, 900);

                $dump->start();
                $gzip->setInput($dump);
                $gzip->run(function ($type, $chunk) use ($absoluteTarget) {
                    if ($type === Process::OUT) {
                        file_put_contents($absoluteTarget, $chunk, FILE_APPEND);
                    }
                });
                if (!$dump->isSuccessful()) {
                    throw new \RuntimeException('mysqldump failed: ' . $dump->getErrorOutput());
                }
                if (!$gzip->isSuccessful()) {
                    throw new \RuntimeException('gzip failed: ' . $gzip->getErrorOutput());
                }
            } finally {
                @unlink($defaults);
            }
            return $targetPath;
        }

        // Fallback: pure PHP dumper (portable — works when mysql-client isn't in the image)
        (new PhpMysqlDumper($cfg))->dumpToGzFile($absoluteTarget);
        return $targetPath;
    }

    private function archiveStorage(string $targetPath): ?string
    {
        $source = storage_path('app/private');
        if (!is_dir($source)) return null;

        Storage::disk('backups')->makeDirectory(dirname($targetPath));
        $absoluteTarget = Storage::disk('backups')->path($targetPath);

        $tar = trim((string) @shell_exec('command -v tar'));
        if ($tar === '') {
            // Without tar we skip file backup rather than fail the whole run.
            Log::warning('[backup] tar not available; skipping file archive');
            return null;
        }

        // CRITICAL: exclude the backups folder from the tar — otherwise every
        // weekly backup includes every previous backup (recursion → explosive
        // growth + file-busy race on the file we're currently writing).
        $cmd = [
            $tar, '-czf', $absoluteTarget,
            '--exclude=' . basename($source) . '/backups',
            '--exclude=' . basename($source) . '/backups/**',
            '-C', dirname($source), basename($source),
        ];
        $p = new Process($cmd, null, null, null, 1800);
        $p->run();
        if (!$p->isSuccessful()) {
            Log::error('[backup] tar failed', ['err' => $p->getErrorOutput()]);
            return null;
        }
        return $targetPath;
    }

    /**
     * Deletes backups older than the retention count for this type.
     *
     * IMPORTANT: A weekly/monthly backup is TWO files ({.sql.gz, -files.tar.gz})
     * that share a timestamp. We group by timestamp so pruning removes both
     * halves of an old backup together — the old bug sorted all files flat and
     * would delete the tarball while leaving the SQL (or vice-versa).
     */
    public function prune(string $type): int
    {
        $folder = $type === self::TYPE_MONTHLY ? 'backups/archive' : "backups/{$type}";
        $keep = self::RETAIN[$type];

        // Group files by their timestamp portion (safco-{type}-YYYY-MM-DD_His...)
        $groups = collect(Storage::disk('backups')->files($folder))
            ->filter(fn ($f) => str_contains($f, "-{$type}-"))
            ->groupBy(function ($f) {
                // Extract timestamp: safco-daily-2026-08-18_133945.sql.gz
                //                    safco-weekly-2026-08-18_134026-files.tar.gz
                if (preg_match('/-(\d{4}-\d{2}-\d{2}_\d{6})/', $f, $m)) return $m[1];
                return $f; // unknown format — treat as own group so we don't merge
            })
            ->sortKeysDesc();

        $stale = $groups->slice($keep);
        $deleted = 0;
        foreach ($stale as $files) {
            foreach ($files as $f) {
                Storage::disk('backups')->delete($f);
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Verify the file is a valid, non-truncated gzip. If it isn't, delete the
     * bad file (so ops don't try to restore from it) and throw.
     */
    private function assertGzipIntegrity(string $relativePath): void
    {
        $abs = Storage::disk('backups')->path($relativePath);
        if (!is_file($abs) || filesize($abs) < 20) {
            @unlink($abs);
            throw new \RuntimeException("Backup gzip empty or missing: {$relativePath}");
        }
        // Try native gzip -t if present; fall back to PHP gzopen probe.
        $gzip = trim((string) @shell_exec('command -v gzip'));
        if ($gzip !== '') {
            $p = new Process([$gzip, '-t', $abs], null, null, null, 120);
            $p->run();
            if (!$p->isSuccessful()) {
                @unlink($abs);
                throw new \RuntimeException("Backup gzip integrity check failed: {$p->getErrorOutput()}");
            }
            return;
        }
        // PHP fallback: read one small chunk. gzread returns false on corrupt.
        $fh = @gzopen($abs, 'rb');
        if ($fh === false) {
            @unlink($abs);
            throw new \RuntimeException("Backup gzip could not be opened: {$relativePath}");
        }
        $probe = @gzread($fh, 1024);
        @gzclose($fh);
        if ($probe === false || $probe === '') {
            @unlink($abs);
            throw new \RuntimeException("Backup gzip appears empty/corrupt: {$relativePath}");
        }
    }

    public function list(): array
    {
        $out = [];
        foreach ([self::TYPE_DAILY, self::TYPE_WEEKLY, self::TYPE_MONTHLY] as $type) {
            $folder = $type === self::TYPE_MONTHLY ? 'backups/archive' : "backups/{$type}";
            $files = collect(Storage::disk('backups')->files($folder))
                ->filter(fn ($f) => !str_ends_with($f, '.gitignore'))
                ->sortDesc()
                ->map(fn ($f) => [
                    'path' => $f,
                    'size' => Storage::disk('backups')->size($f),
                    'modified' => Storage::disk('backups')->lastModified($f),
                ])
                ->values()
                ->all();
            $out[$type] = $files;
        }
        return $out;
    }
}
