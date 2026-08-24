<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

/**
 * SRS Non-Functional Requirements — daily/weekly/monthly backups.
 *
 * Usage:
 *   php artisan backup:run                → daily
 *   php artisan backup:run --type=weekly  → DB + files tarball
 *   php artisan backup:run --type=monthly → same as weekly, archive folder
 */
class BackupRunCommand extends Command
{
    protected $signature = 'backup:run {--type=daily : daily|weekly|monthly}';
    protected $description = 'Create a database + optional file backup';

    public function handle(BackupService $svc): int
    {
        $type = $this->option('type');
        try {
            $result = $svc->run($type);
        } catch (\Throwable $e) {
            $this->error("Backup failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info("Backup ({$type}) complete.");
        $this->line("  DB dump : {$result['db_file']}");
        $this->line("  Size    : " . $this->human($result['db_size']));
        if ($result['files_file']) {
            $this->line("  Files   : {$result['files_file']}");
            $this->line("  Size    : " . $this->human($result['files_size']));
        }
        $this->line("  Pruned  : {$result['pruned']} old backup(s)");
        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1024 * 1024 * 1024) return round($bytes / 1024 / 1024, 1) . ' MB';
        return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
    }
}
