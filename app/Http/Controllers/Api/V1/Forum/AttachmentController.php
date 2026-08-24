<?php

namespace App\Http\Controllers\Api\V1\Forum;

use App\Http\Controllers\Controller;
use App\Models\Forum\ForumAttachment;
use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumThread;
use App\Services\TrainerPortal\FileValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * SRS Module 14 — Attachment upload/download for forum threads + posts.
 *
 * Contract:
 *  - Only the author of the target thread/post OR a moderator may attach.
 *  - Locked threads reject new attachments.
 *  - Hidden threads/posts reject attachments (no smuggling into hidden content).
 *  - Files are magic-byte validated (defense-in-depth beyond MIME check).
 *  - Files stored on PRIVATE 'local' disk; download links are signed + audience-bound.
 *  - Cap of 5 attachments per target so a thread doesn't become a file dump.
 */
class AttachmentController extends Controller
{
    private const MAX_KB = 8192;                 // 8 MB per file
    private const MAX_PER_TARGET = 5;
    private const ALLOWED_MIMES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
    ];

    /** POST /forum/threads/{thread}/attachments */
    public function storeForThread(ForumThread $thread, Request $request): JsonResponse
    {
        $this->guardCanAttach($thread->author_id, $request);
        if ($thread->is_hidden || $thread->is_locked) {
            return $this->error('Cannot attach to a locked or hidden thread.', 422);
        }
        if (ForumAttachment::where('attachable_type', 'thread')->where('attachable_id', $thread->id)->count() >= self::MAX_PER_TARGET) {
            return $this->error('Maximum of ' . self::MAX_PER_TARGET . ' attachments per thread.', 422);
        }
        return $this->doUpload($thread, 'thread', $request);
    }

    /** POST /forum/posts/{post}/attachments */
    public function storeForPost(ForumPost $post, Request $request): JsonResponse
    {
        $this->guardCanAttach($post->author_id, $request);
        if ($post->is_hidden) {
            return $this->error('Cannot attach to a hidden post.', 422);
        }
        $thread = $post->thread;
        if ($thread && ($thread->is_hidden || $thread->is_locked)) {
            return $this->error('Cannot attach: thread is locked or hidden.', 422);
        }
        if (ForumAttachment::where('attachable_type', 'post')->where('attachable_id', $post->id)->count() >= self::MAX_PER_TARGET) {
            return $this->error('Maximum of ' . self::MAX_PER_TARGET . ' attachments per post.', 422);
        }
        return $this->doUpload($post, 'post', $request);
    }

    private function guardCanAttach(int $ownerUserId, Request $request): void
    {
        $user = $request->user();
        $isModerator = $user->hasAnyRole(['system_admin', 'trainer', 'facilitator']);
        if ((int) $ownerUserId !== (int) $user->id && !$isModerator) {
            abort(403, 'You cannot attach files to another user\'s post.');
        }
    }

    private function doUpload($target, string $type, Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:' . self::MAX_KB, 'mimetypes:' . implode(',', self::ALLOWED_MIMES)],
        ]);
        $file = $request->file('file');

        try {
            FileValidator::assertSafeProofFile($file);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $user = $request->user();
        $folder = "forum-attachments/{$type}s/" . $target->uuid;
        $stored = $file->storeAs(
            $folder,
            Str::random(24) . '.' . $file->getClientOriginalExtension(),
            'local'
        );

        $attachment = ForumAttachment::create([
            'attachable_type' => $type,
            'attachable_id' => $target->id,
            'uploaded_by' => $user->id,
            'file_path' => $stored,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);

        return $this->success([
            'uuid' => $attachment->uuid,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size,
        ], 'Attachment uploaded', 201);
    }

    /** GET /forum/attachments/{attachment:uuid}/url */
    public function url(ForumAttachment $attachment, Request $request): JsonResponse
    {
        $user = $request->user();
        // Any authenticated user can request a URL — download endpoint verifies auth again.
        $signed = URL::temporarySignedRoute(
            'forum.attachments.download',
            now()->addMinutes(10),
            ['attachment' => $attachment->uuid, 'audience' => $user->id],
        );
        return $this->success(['download_url' => $signed]);
    }

    /** GET /forum/attachments/{attachment:uuid}/download — audience-bound signed URL */
    public function download(ForumAttachment $attachment, Request $request)
    {
        if (!$request->hasValidSignature()) abort(403, 'Expired or invalid link.');
        $audience = (int) $request->query('audience', 0);
        if (!$request->user() || $request->user()->id !== $audience) {
            abort(403, 'This download link is bound to a different account.');
        }
        if (!Storage::disk('local')->exists($attachment->file_path)) {
            abort(404, 'File no longer available.');
        }
        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->file_name,
            ['Content-Type' => $attachment->mime_type],
        );
    }

    /** DELETE /forum/attachments/{attachment:uuid} */
    public function destroy(ForumAttachment $attachment, Request $request): JsonResponse
    {
        $user = $request->user();
        $isModerator = $user->hasAnyRole(['system_admin', 'trainer', 'facilitator']);
        if ((int) $attachment->uploaded_by !== (int) $user->id && !$isModerator) {
            return $this->error('You cannot delete this attachment.', 403);
        }
        Storage::disk('local')->delete($attachment->file_path);
        $attachment->delete();
        return $this->success(null, 'Attachment removed');
    }
}
