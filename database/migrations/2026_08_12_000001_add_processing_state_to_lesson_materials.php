<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS Module 3 — professional performance requirements.
 * Adds asynchronous processing state so uploads no longer block the request:
 *   pending → processing → ready | failed
 *
 * Also stores extracted media metadata (thumbnail, duration, dimensions, pages)
 * needed for YouTube-style playback + lazy grid loading.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lesson_materials', function (Blueprint $t) {
            $t->enum('processing_status', ['pending', 'processing', 'ready', 'failed'])
                ->default('ready')
                ->after('metadata');
            $t->unsignedTinyInteger('processing_progress')->default(0)->after('processing_status');
            $t->text('processing_error')->nullable()->after('processing_progress');

            // Extracted metadata — populated by the job
            $t->string('thumbnail_url', 1024)->nullable()->after('processing_error');
            $t->unsignedInteger('duration_seconds')->nullable()->after('thumbnail_url');
            $t->unsignedInteger('width')->nullable()->after('duration_seconds');
            $t->unsignedInteger('height')->nullable()->after('width');
            $t->unsignedInteger('page_count')->nullable()->after('height');

            $t->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_materials', function (Blueprint $t) {
            $t->dropIndex(['processing_status']);
            $t->dropColumn([
                'processing_status', 'processing_progress', 'processing_error',
                'thumbnail_url', 'duration_seconds', 'width', 'height', 'page_count',
            ]);
        });
    }
};
