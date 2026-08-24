<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * SRS Non-Functional Requirements — DB restore from a backup file.
 *
 * Usage:
 *   php artisan backup:restore path/to/safco-daily-2026-08-18_020000.sql.gz
 *   php artisan backup:restore path/to/... --force  (skip confirmation)
 *
 * Safety rails:
 *  - Refuses to run in production without --force
 *  - Interactive confirmation ("Type YES to overwrite <db>")
 *  - Verifies gzip integrity BEFORE touching the DB
 *  - Uses mysql client (streaming); no huge in-memory buffers
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore
        {file : Backup path (relative to backups disk) or absolute path}
        {--force : Skip prompts (required in production)}';
    protected $description = 'Restore a backup SQL dump into the current database';

    public function handle(): int
    {
        $file = $this->argument('file');
        $abs = is_file($file) ? $file : Storage::disk('backups')->path($file);
        if (!is_file($abs)) {
            $this->error("Backup file not found: {$file}");
            return self::FAILURE;
        }

        // Verify gzip integrity BEFORE touching the DB — refuse to restore corrupt backups
        $gzip = trim((string) @shell_exec('command -v gzip'));
        if ($gzip === '') {
            $this->error('gzip binary not available');
            return self::FAILURE;
        }
        $t = new Process([$gzip, '-t', $abs]);
        $t->run();
        if (!$t->isSuccessful()) {
            $this->error("Backup gzip integrity failed — refusing to restore.");
            $this->line($t->getErrorOutput());
            return self::FAILURE;
        }
        $sizeMb = round(filesize($abs) / 1024 / 1024, 2);

        $cfg = config('database.connections.' . config('database.default'));
        $env = app()->environment();

        $this->warn("╔════════════════════════════════════════════════════════════════╗");
        $this->warn("║ DESTRUCTIVE OPERATION — this will OVERWRITE the entire database ║");
        $this->warn("╚════════════════════════════════════════════════════════════════╝");
        $this->line("  Environment : {$env}");
        $this->line("  Database    : {$cfg['database']} @ {$cfg['host']}:{$cfg['port']}");
        $this->line("  Backup file : {$abs} ({$sizeMb} MB)");

        if ($env === 'production' && !$this->option('force')) {
            $this->error("Production restore requires --force flag.");
            return self::FAILURE;
        }
        if (!$this->option('force')) {
            $confirm = $this->ask("Type YES to overwrite {$cfg['database']}");
            if ($confirm !== 'YES') {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        $mysql = trim((string) @shell_exec('command -v mysql'));
        if ($mysql === '') {
            $this->error('mysql client not available in this container.');
            return self::FAILURE;
        }

        // Use a defaults file so password never appears on the command line
        $defaults = tempnam(sys_get_temp_dir(), 'mycli');
        file_put_contents($defaults, "[client]\nuser={$cfg['username']}\npassword={$cfg['password']}\nhost={$cfg['host']}\nport={$cfg['port']}\n");
        @chmod($defaults, 0600);

        try {
            $gunzip = new Process([$gzip, '-dc', $abs]);
            $mysqlP = new Process([$mysql, "--defaults-extra-file={$defaults}", $cfg['database']]);

            $gunzip->start();
            $mysqlP->setInput($gunzip);
            $mysqlP->setTimeout(3600); // 1 hour ceiling for very large restores
            $mysqlP->run();

            if (!$gunzip->isSuccessful()) {
                $this->error('gunzip failed: ' . $gunzip->getErrorOutput());
                return self::FAILURE;
            }
            if (!$mysqlP->isSuccessful()) {
                $this->error('mysql restore failed: ' . $mysqlP->getErrorOutput());
                return self::FAILURE;
            }
        } finally {
            @unlink($defaults);
        }

        $this->info('Restore complete.');
        $this->warn('You should run `php artisan migrate` to apply any newer migrations that the backup did not have.');
        return self::SUCCESS;
    }
}
