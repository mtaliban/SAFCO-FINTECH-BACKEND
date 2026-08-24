<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class BackupListCommand extends Command
{
    protected $signature = 'backup:list';
    protected $description = 'List existing backups by type';

    public function handle(BackupService $svc): int
    {
        foreach ($svc->list() as $type => $files) {
            $this->info(strtoupper($type) . " (" . count($files) . " file(s))");
            foreach ($files as $f) {
                $mb = round(($f['size'] ?? 0) / 1024 / 1024, 2);
                $when = date('Y-m-d H:i', $f['modified'] ?? time());
                $this->line("  {$when}  {$mb} MB  {$f['path']}");
            }
        }
        return self::SUCCESS;
    }
}
