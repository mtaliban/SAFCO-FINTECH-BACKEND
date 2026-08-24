<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS Module 9 — Assignments.
 * The trainer uploads a brief document (PDF / DOCX / XLSX / ZIP) that students download.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->string('brief_file_url')->nullable()->after('instructions');
            $table->string('brief_file_name')->nullable()->after('brief_file_url');
            $table->unsignedBigInteger('brief_file_size')->nullable()->after('brief_file_name');
            $table->string('brief_mime_type')->nullable()->after('brief_file_size');
        });

        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->string('file_name')->nullable()->after('file_url');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_name');
            $table->string('mime_type')->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->dropColumn(['file_name', 'file_size', 'mime_type']);
        });
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn(['brief_file_url', 'brief_file_name', 'brief_file_size', 'brief_mime_type']);
        });
    }
};
