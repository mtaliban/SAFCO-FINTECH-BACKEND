<?php

namespace App\Http\Controllers\Api\V1\Course;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMaterialUpload;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SRS Module 3 — Learning Materials CRUD.
 * Accepts uploads (docs/videos/SCORM) OR external URLs (YouTube/Vimeo/HTML5 embed).
 */
class MaterialController extends Controller
{
    // Max file sizes in KB
    private const MAX_DOC_KB = 20 * 1024;      // 20 MB
    private const MAX_VIDEO_KB = 100 * 1024;   // 100 MB (dev)
    private const MAX_SCORM_KB = 50 * 1024;    // 50 MB

    // MIME → SRS type
    private const MIME_TO_TYPE = [
        'application/pdf' => 'document_pdf',
        'application/msword' => 'document_word',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document_word',
        'application/vnd.ms-excel' => 'document_excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'document_excel',
        'application/vnd.ms-powerpoint' => 'document_powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'document_powerpoint',
        'video/mp4' => 'video_mp4',
        'video/webm' => 'video_mp4',
        'video/quicktime' => 'video_mp4',
        'application/zip' => 'interactive_scorm', // guess — user should specify for HTML5
    ];

    /** POST /api/v1/lessons/{lesson:uuid}/materials */
    public function store(Lesson $lesson, Request $request): JsonResponse
    {
        $this->authorizeCourseOwner($lesson, $request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['nullable', Rule::in(LessonMaterial::TYPES)],
            'url' => ['nullable', 'string', 'max:1024'],
            'file' => ['nullable', 'file', 'max:'.self::MAX_VIDEO_KB],
        ]);

        if (empty($data['url']) && !$request->hasFile('file')) {
            return $this->error('Provide either a file upload or a URL.', 422);
        }

        // URL-based (YouTube, Vimeo, HTML5 embed) — no processing needed, ready immediately
        if (!empty($data['url'])) {
            $detected = $this->detectUrlType($data['url']);
            $type = $data['type'] ?? $detected;
            if (!$type) {
                return $this->error('Cannot detect material type from URL — pass `type` explicitly.', 422);
            }

            $material = $lesson->materials()->create([
                'type' => $type,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'url' => $data['url'],
                'position' => $lesson->materials()->max('position') + 1,
                'metadata' => $this->extractMetadata($data['url'], $type),
                'processing_status' => 'ready',
                'processing_progress' => 100,
            ]);
            return $this->success($this->transform($material), 'Material added', 201);
        }

        // File upload path
        $file = $request->file('file');
        $mime = $file->getMimeType();
        $ext = strtolower($file->getClientOriginalExtension());
        $type = $data['type'] ?? $this->detectFileType($mime, $ext);
        if (!$type) {
            return $this->error("Unsupported file type ({$mime} / .{$ext}).", 422);
        }

        // Per-category size cap
        $sizeKb = round($file->getSize() / 1024);
        if (in_array($type, LessonMaterial::CATEGORIES['documents'], true) && $sizeKb > self::MAX_DOC_KB) {
            return $this->error('Document exceeds 20 MB.', 422);
        }
        if (in_array($type, LessonMaterial::CATEGORIES['videos'], true) && $sizeKb > self::MAX_VIDEO_KB) {
            return $this->error('Video exceeds 100 MB.', 422);
        }
        if (in_array($type, LessonMaterial::CATEGORIES['interactive'], true) && $sizeKb > self::MAX_SCORM_KB) {
            return $this->error('Package exceeds 50 MB.', 422);
        }

        $disk = config('filesystems.default', 's3');
        $path = $file->store("lessons/{$lesson->uuid}/materials", $disk);
        $url  = Storage::disk($disk)->url($path);

        $material = $lesson->materials()->create([
            'type' => $type,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'url' => $url,
            'mime_type' => $mime,
            'file_size' => $file->getSize(),
            'position' => $lesson->materials()->max('position') + 1,
            'metadata' => ['original_name' => $file->getClientOriginalName()],
            'processing_status' => 'pending',
            'processing_progress' => 0,
        ]);

        // Kick off async processing — extraction, thumbnails, MQTT progress events
        ProcessMaterialUpload::dispatch($material->uuid)->onQueue('media');

        // 202 Accepted — client will listen on MQTT topic safco/lms/material/{uuid}/status
        // or poll GET /materials/{uuid}/status
        return $this->success($this->transform($material), 'Upload accepted — processing started', 202);
    }

    /** GET /api/v1/materials/{material:uuid}/status — poll fallback for MQTT */
    public function status(LessonMaterial $material): JsonResponse
    {
        return $this->success([
            'material_uuid' => $material->uuid,
            'status' => $material->processing_status,
            'progress' => (int) $material->processing_progress,
            'error' => $material->processing_error,
            'thumbnail_url' => $material->thumbnail_url,
            'duration_seconds' => $material->duration_seconds,
            'page_count' => $material->page_count,
            'width' => $material->width,
            'height' => $material->height,
        ]);
    }

    /**
     * GET /api/v1/materials/{material:uuid}/stream
     * YouTube-style video streaming — HTTP Range Requests (RFC 7233).
     * Enables seeking in the player without downloading the whole file.
     */
    public function stream(LessonMaterial $material, Request $request): StreamedResponse|JsonResponse
    {
        if ($material->processing_status !== 'ready') {
            return $this->error('Material still processing.', 425);
        }

        $url = $material->url;

        // S3 / external URL — redirect; S3 supports Range requests natively so
        // the browser <video> element can seek without any proxy logic here.
        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            // For private S3 buckets generate a short-lived signed URL (15 min).
            // For public buckets the URL is already usable — redirect is fine either way.
            try {
                $disk = config('filesystems.default', 's3');
                $path = $this->s3PathFromUrl($url);
                $signed = Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(15));
                return redirect()->away($signed);
            } catch (\Throwable) {
                // Bucket is public or disk doesn't support signed URLs — redirect directly.
                return redirect()->away($url);
            }
        }

        // Legacy local /storage/ files (backward compat during migration)
        if (!str_starts_with($url, '/storage/')) {
            return $this->error('This material is external — use the URL directly.', 400);
        }

        $path = Storage::disk('public')->path(str_replace('/storage/', '', $url));
        if (!file_exists($path)) return $this->error('File missing.', 404);

        $size   = filesize($path);
        $mime   = $material->mime_type ?: mime_content_type($path) ?: 'application/octet-stream';
        $start  = 0;
        $end    = $size - 1;
        $status = 200;
        $headers = [
            'Content-Type'   => $mime,
            'Accept-Ranges'  => 'bytes',
            'Cache-Control'  => 'public, max-age=3600',
            'Content-Length' => (string) $size,
        ];

        $range = $request->header('Range');
        if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            $start = $m[1] !== '' ? (int) $m[1] : 0;
            $end   = $m[2] !== '' ? (int) $m[2] : $size - 1;
            if ($start > $end || $end >= $size) {
                return $this->error('Requested range not satisfiable.', 416);
            }
            $status = 206;
            $headers['Content-Range']  = "bytes {$start}-{$end}/{$size}";
            $headers['Content-Length'] = (string) ($end - $start + 1);
        }

        return response()->stream(function () use ($path, $start, $end) {
            $fh = fopen($path, 'rb');
            fseek($fh, $start);
            $bytesToRead = $end - $start + 1;
            $chunk = 8192;
            while (!feof($fh) && $bytesToRead > 0 && connection_status() === CONNECTION_NORMAL) {
                $read = min($chunk, $bytesToRead);
                echo fread($fh, $read);
                @ob_flush(); @flush();
                $bytesToRead -= $read;
            }
            fclose($fh);
        }, $status, $headers);
    }

    /** Extract the S3 object key from a full S3 URL. */
    private function s3PathFromUrl(string $url): string
    {
        $bucket = config('filesystems.disks.s3.bucket', '');
        $region = config('filesystems.disks.s3.region', '');

        // Standard: https://bucket.s3.region.amazonaws.com/key
        foreach ([
            "https://{$bucket}.s3.{$region}.amazonaws.com/",
            "https://{$bucket}.s3.amazonaws.com/",
            "https://s3.{$region}.amazonaws.com/{$bucket}/",
        ] as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return urldecode(substr($url, strlen($prefix)));
            }
        }
        // Custom domain / CDN — extract path component
        $path = parse_url($url, PHP_URL_PATH);
        return ltrim($path ?? $url, '/');
    }

    /** PATCH /api/v1/materials/{material:uuid} */
    public function update(LessonMaterial $material, Request $request): JsonResponse
    {
        $this->authorizeCourseOwner($material->lesson, $request);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'position' => ['sometimes', 'integer'],
        ]);
        $material->update($data);
        return $this->success($this->transform($material->fresh()), 'Updated');
    }

    /** DELETE /api/v1/materials/{material:uuid} */
    public function destroy(LessonMaterial $material, Request $request): JsonResponse
    {
        $this->authorizeCourseOwner($material->lesson, $request);

        // Remove file from storage
        $url = $material->url;
        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            $disk = config('filesystems.default', 's3');
            try { Storage::disk($disk)->delete($this->s3PathFromUrl($url)); } catch (\Throwable) {}
        } elseif (str_starts_with($url, '/storage/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $url));
        }
        $material->delete();
        return $this->success(null, 'Material deleted');
    }

    /** POST /api/v1/lessons/{lesson:uuid}/materials/reorder */
    public function reorder(Lesson $lesson, Request $request): JsonResponse
    {
        $this->authorizeCourseOwner($lesson, $request);
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'exists:lesson_materials,uuid'],
        ]);
        foreach ($data['order'] as $i => $uuid) {
            LessonMaterial::where('uuid', $uuid)->where('lesson_id', $lesson->id)->update(['position' => $i]);
        }
        return $this->success(null, 'Reordered');
    }

    // --- Helpers ---

    private function authorizeCourseOwner(Lesson $lesson, Request $request): void
    {
        $user = $request->user();
        if ($lesson->module->course->instructor_id !== $user->id && !$user->hasRole('system_admin')) {
            abort(403, 'Not your course.');
        }
    }

    private function detectUrlType(string $url): ?string
    {
        if (preg_match('/(?:youtube\.com|youtu\.be)/i', $url)) return 'video_youtube';
        if (preg_match('/vimeo\.com/i', $url)) return 'video_vimeo';
        $lower = strtolower($url);
        if (str_ends_with($lower, '.pdf')) return 'document_pdf';
        if (str_ends_with($lower, '.doc') || str_ends_with($lower, '.docx')) return 'document_word';
        if (str_ends_with($lower, '.xls') || str_ends_with($lower, '.xlsx')) return 'document_excel';
        if (str_ends_with($lower, '.ppt') || str_ends_with($lower, '.pptx')) return 'document_powerpoint';
        if (str_ends_with($lower, '.mp4') || str_ends_with($lower, '.webm')) return 'video_mp4';
        // External HTML5 assumed if a full URL and none of the above
        return 'interactive_html5';
    }

    private function detectFileType(?string $mime, string $ext): ?string
    {
        if ($mime && isset(self::MIME_TO_TYPE[$mime])) return self::MIME_TO_TYPE[$mime];
        return match ($ext) {
            'pdf' => 'document_pdf',
            'doc', 'docx' => 'document_word',
            'xls', 'xlsx' => 'document_excel',
            'ppt', 'pptx' => 'document_powerpoint',
            'mp4', 'webm', 'mov' => 'video_mp4',
            'zip' => 'interactive_scorm', // trainer should override if HTML5 zip
            default => null,
        };
    }

    private function extractMetadata(string $url, string $type): array
    {
        $meta = [];
        if ($type === 'video_youtube') {
            if (preg_match('/(?:v=|youtu\.be\/)([\w-]{11})/', $url, $m)) {
                $meta['embed_id'] = $m[1];
                $meta['embed_url'] = "https://www.youtube.com/embed/{$m[1]}";
            }
        } elseif ($type === 'video_vimeo') {
            if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
                $meta['embed_id'] = $m[1];
                $meta['embed_url'] = "https://player.vimeo.com/video/{$m[1]}";
            }
        }
        return $meta;
    }

    private function transform(LessonMaterial $m): array
    {
        return [
            'uuid' => $m->uuid,
            'type' => $m->type,
            'category' => $m->category,
            'title' => $m->title,
            'description' => $m->description,
            'url' => $m->url,
            'mime_type' => $m->mime_type,
            'file_size' => $m->file_size,
            'position' => $m->position,
            'metadata' => $m->metadata,
            // Async processing state — frontend renders progress bar + status pill
            'processing_status' => $m->processing_status,
            'processing_progress' => (int) $m->processing_progress,
            'processing_error' => $m->processing_error,
            // Extracted metadata (populated by ProcessMaterialUpload job)
            'thumbnail_url' => $m->thumbnail_url,
            'duration_seconds' => $m->duration_seconds,
            'page_count' => $m->page_count,
            'width' => $m->width,
            'height' => $m->height,
            // Streaming endpoint — used for S3 files and legacy local files
            'stream_url' => (!str_starts_with($m->url, 'https://www.youtube') && !str_starts_with($m->url, 'https://player.vimeo'))
                ? "/api/v1/materials/{$m->uuid}/stream" : null,
        ];
    }
}
