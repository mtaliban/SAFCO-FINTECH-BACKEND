<?php

namespace App\Jobs;

use App\Models\LessonMaterial;
use App\Services\EventBus\MqttPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SRS Module 3 — professional async material processing pipeline.
 *
 * Runs on `safco-worker` (queue: media). Reads the freshly-uploaded file,
 * extracts metadata, generates preview thumbnails, and emits progress events
 * over MQTT (topic: safco/lms/material/{uuid}/status) so the frontend can
 * light up UI without polling.
 *
 * Stages emitted: 25% (starting) → 50% (metadata) → 75% (thumbnail) → 100% (ready)
 */
class ProcessMaterialUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 min per material

    public function __construct(public string $materialUuid) {}

    public function handle(MqttPublisher $mqtt): void
    {
        $material = LessonMaterial::where('uuid', $this->materialUuid)->first();
        if (!$material) {
            Log::warning('ProcessMaterialUpload: material not found', ['uuid' => $this->materialUuid]);
            return;
        }

        try {
            $this->update($material, $mqtt, 'processing', 25, 'Starting');

            // YouTube / Vimeo / HTML5 embed — nothing to process locally
            if (in_array($material->type, ['video_youtube', 'video_vimeo', 'interactive_html5'], true)) {
                $this->update($material, $mqtt, 'ready', 100, 'External URL — no processing needed');
                return;
            }

            // Resolve local file path — download from S3 to a temp file if needed
            $tmpFile = null;
            $fullPath = $this->resolveLocalPath($material->url, $tmpFile);

            try {
                // Stage 2: extract metadata
                $meta = $this->extractMetadata($material, $fullPath);
                $material->fill($meta);
                $material->save();
                $this->update($material, $mqtt, 'processing', 50, 'Metadata extracted', $meta);

                // Stage 3: generate thumbnail
                $thumbnail = $this->generateThumbnail($material, $fullPath);
                if ($thumbnail) {
                    $material->thumbnail_url = $thumbnail;
                    $material->save();
                }
                $this->update($material, $mqtt, 'processing', 75, 'Thumbnail generated', ['thumbnail_url' => $thumbnail]);

                // Stage 4: done
                $this->update($material, $mqtt, 'ready', 100, 'Ready');
            } finally {
                if ($tmpFile && file_exists($tmpFile)) {
                    @unlink($tmpFile);
                }
            }

            Log::info('Material processing complete', [
                'material_uuid' => $material->uuid,
                'type' => $material->type,
                'duration_seconds' => $material->duration_seconds,
            ]);
        } catch (\Throwable $e) {
            $material->update([
                'processing_status' => 'failed',
                'processing_error' => $e->getMessage(),
            ]);
            $this->publishStatus($mqtt, $material, ['error' => $e->getMessage()]);
            Log::error('Material processing failed', [
                'material_uuid' => $material->uuid,
                'error' => $e->getMessage(),
            ]);
            throw $e; // let queue retry
        }
    }

    private function update(LessonMaterial $material, MqttPublisher $mqtt, string $status, int $progress, string $note, array $extra = []): void
    {
        $material->processing_status = $status;
        $material->processing_progress = $progress;
        if ($status === 'failed') {
            $material->processing_error = $note;
        }
        $material->save();
        $this->publishStatus($mqtt, $material, $extra);
    }

    private function publishStatus(MqttPublisher $mqtt, LessonMaterial $material, array $extra = []): void
    {
        $mqtt->publishRaw("safco/lms/material/{$material->uuid}/status", array_merge([
            'material_uuid' => $material->uuid,
            'status' => $material->processing_status,
            'progress' => (int) $material->processing_progress,
            'thumbnail_url' => $material->thumbnail_url,
            'duration_seconds' => $material->duration_seconds,
            'page_count' => $material->page_count,
            'width' => $material->width,
            'height' => $material->height,
            'error' => $material->processing_error,
            'ts' => now()->toIso8601String(),
        ], $extra));
    }

    /**
     * Returns a local file path for the material.
     * For S3 / remote URLs: downloads to a temp file (caller must delete).
     * For legacy /storage/ paths: resolves the local path directly.
     */
    private function resolveLocalPath(string $url, ?string &$tmpFile): string
    {
        if (str_starts_with($url, '/storage/')) {
            $path = Storage::disk('public')->path(str_replace('/storage/', '', $url));
            if (!file_exists($path)) {
                throw new \RuntimeException("File not found at {$path}");
            }
            return $path;
        }

        // S3 or other remote URL — download to temp
        $disk = config('filesystems.default', 's3');
        $s3Path = $this->s3PathFromUrl($url);
        $contents = Storage::disk($disk)->get($s3Path);
        if ($contents === null || $contents === false) {
            throw new \RuntimeException("Could not download file from storage: {$url}");
        }
        $ext = pathinfo($s3Path, PATHINFO_EXTENSION);
        $tmpFile = tempnam(sys_get_temp_dir(), 'safco_') . ($ext ? ".{$ext}" : '');
        file_put_contents($tmpFile, $contents);
        return $tmpFile;
    }

    private function s3PathFromUrl(string $url): string
    {
        $bucket = config('filesystems.disks.s3.bucket', '');
        $region = config('filesystems.disks.s3.region', '');
        foreach ([
            "https://{$bucket}.s3.{$region}.amazonaws.com/",
            "https://{$bucket}.s3.amazonaws.com/",
            "https://s3.{$region}.amazonaws.com/{$bucket}/",
        ] as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return urldecode(substr($url, strlen($prefix)));
            }
        }
        return ltrim(parse_url($url, PHP_URL_PATH) ?? $url, '/');
    }

    /**
     * Extracts metadata without relying on FFmpeg — just parses well-known
     * headers ourselves so it works out of the box in the container.
     */
    private function extractMetadata(LessonMaterial $material, string $path): array
    {
        $out = ['file_size' => filesize($path) ?: null];

        try {
            if (str_starts_with($material->type, 'video_')) {
                $duration = $this->probeMp4Duration($path);
                if ($duration) $out['duration_seconds'] = $duration;
            } elseif ($material->type === 'document_pdf') {
                $pageCount = $this->probePdfPages($path);
                if ($pageCount) $out['page_count'] = $pageCount;
            }
        } catch (\Throwable $e) {
            Log::debug('Metadata probe failed', ['error' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Parses MP4 `mvhd` atom to read duration without FFmpeg.
     * Works for all standard MP4 files (iso, isom, mp42, M4V).
     */
    private function probeMp4Duration(string $path): ?int
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) return null;
        try {
            $offset = 0;
            while (!feof($fh)) {
                fseek($fh, $offset);
                $header = fread($fh, 8);
                if (strlen($header) < 8) return null;
                $size = unpack('N', substr($header, 0, 4))[1];
                $type = substr($header, 4, 4);
                if ($size === 1) {
                    $ext = fread($fh, 8);
                    $size = unpack('J', $ext)[1];
                }
                if ($type === 'moov') {
                    // Search inside moov for mvhd
                    $moovEnd = $offset + $size;
                    $cursor = ftell($fh);
                    while ($cursor < $moovEnd) {
                        fseek($fh, $cursor);
                        $sub = fread($fh, 8);
                        if (strlen($sub) < 8) return null;
                        $subSize = unpack('N', substr($sub, 0, 4))[1];
                        $subType = substr($sub, 4, 4);
                        if ($subType === 'mvhd') {
                            $version = ord(fread($fh, 1));
                            fread($fh, 3); // flags
                            if ($version === 1) {
                                fread($fh, 16); // creation + mod times (64-bit)
                                $timescale = unpack('N', fread($fh, 4))[1];
                                $duration = unpack('J', fread($fh, 8))[1];
                            } else {
                                fread($fh, 8);
                                $timescale = unpack('N', fread($fh, 4))[1];
                                $duration = unpack('N', fread($fh, 4))[1];
                            }
                            return $timescale ? (int) round($duration / $timescale) : null;
                        }
                        $cursor += $subSize;
                        if ($subSize <= 0) break;
                    }
                }
                $offset += $size;
                if ($size <= 0) break;
            }
        } finally {
            fclose($fh);
        }
        return null;
    }

    /** Counts PDF pages by scanning for `/Type /Page` markers. Good enough w/o Imagick. */
    private function probePdfPages(string $path): ?int
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) return null;
        $count = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            $count += preg_match_all('/\/Type\s*\/Page[^s]/', $chunk);
        }
        fclose($handle);
        return $count ?: null;
    }

    /**
     * Attempts PDF first-page thumbnail using Imagick if present.
     * For videos: without FFmpeg we can't extract a poster frame — return null
     * and the frontend falls back to a generic icon.
     */
    private function generateThumbnail(LessonMaterial $material, string $path): ?string
    {
        if ($material->type !== 'document_pdf') return null;
        if (!extension_loaded('imagick')) return null;

        try {
            $img = new \Imagick();
            $img->setResolution(96, 96);
            $img->readImage($path.'[0]');
            $img->setImageFormat('jpg');
            $img->thumbnailImage(400, 0);
            $img->setImageCompressionQuality(80);
            $jpgData = (string) $img;
            $img->clear();

            $disk = config('filesystems.default', 's3');
            $storagePath = "lessons/{$material->lesson->uuid}/materials/{$material->uuid}/thumbnail.jpg";
            Storage::disk($disk)->put($storagePath, $jpgData, 'public');
            return Storage::disk($disk)->url($storagePath);
        } catch (\Throwable $e) {
            Log::debug('PDF thumbnail generation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
