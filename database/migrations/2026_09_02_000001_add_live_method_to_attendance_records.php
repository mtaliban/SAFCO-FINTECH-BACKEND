<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE attendance_records MODIFY COLUMN method ENUM('qr','manual','auto','live') NOT NULL DEFAULT 'qr'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE attendance_records MODIFY COLUMN method ENUM('qr','manual','auto') NOT NULL DEFAULT 'qr'");
    }
};
