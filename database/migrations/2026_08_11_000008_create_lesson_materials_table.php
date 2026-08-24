<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS Module 3 — Learning Materials.
 * Supports every SRS-listed type: documents, videos, interactive content.
 *
 *   Documents:  PDF, Word, Excel, PowerPoint
 *   Videos:     MP4, YouTube, Vimeo
 *   Interactive: SCORM, HTML5
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_materials', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();

            // Category groupings match SRS exactly
            $t->enum('type', [
                // Documents (SRS)
                'document_pdf',
                'document_word',
                'document_excel',
                'document_powerpoint',
                // Videos (SRS)
                'video_mp4',
                'video_youtube',
                'video_vimeo',
                // Interactive (SRS)
                'interactive_scorm',
                'interactive_html5',
            ]);

            $t->string('title');
            $t->text('description')->nullable();
            $t->string('url', 1024);              // storage path (/storage/…) or external URL
            $t->string('mime_type', 100)->nullable();
            $t->unsignedBigInteger('file_size')->nullable(); // bytes
            $t->integer('position')->default(0);
            $t->json('metadata')->nullable();     // duration, embed_id, provider hints

            $t->timestamps();
            $t->softDeletes();

            $t->index(['lesson_id', 'position']);
            $t->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_materials');
    }
};
