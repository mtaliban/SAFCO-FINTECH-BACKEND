<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite (used in CI tests) has no ENUM — TEXT columns accept any value, skip.
        if (DB::getDriverName() === 'sqlite') return;

        DB::statement("ALTER TABLE attendance_records MODIFY COLUMN method ENUM('qr','manual','auto','live') NOT NULL DEFAULT 'qr'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') return;

        DB::statement("ALTER TABLE attendance_records MODIFY COLUMN method ENUM('qr','manual','auto') NOT NULL DEFAULT 'qr'");
    }
};
