<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('quiz_session_participants', function (Blueprint $table) {
            $table->boolean('is_late_join')->default(false)->after('is_connected');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_session_participants', function (Blueprint $table) {
            $table->dropColumn('is_late_join');
        });
    }
};
