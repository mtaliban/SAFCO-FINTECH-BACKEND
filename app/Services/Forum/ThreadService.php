<?php

namespace App\Services\Forum;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Forum\ForumCategory;
use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumSubscription;
use App\Models\Forum\ForumThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SRS Module 14 — Thread lifecycle.
 *
 * Enforces:
 *  - Category rules (assignments require assignment_id + enrollment).
 *  - Author is auto-subscribed to their own thread.
 *  - Mentions in opening body trigger notifications.
 *  - Locked/hidden threads reject new posts (in PostService).
 */
class ThreadService
{
    public function __construct(
        private readonly MentionParser $mentions,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{title:string,body:string,tags?:array,course_id?:int,assignment_id?:int}  $data
     */
    public function create(User $author, ForumCategory $category, array $data): ForumThread
    {
        if ($category->requires_course_context) {
            if (empty($data['assignment_id'])) {
                throw new \DomainException("This category requires an assignment context.");
            }
            $assignment = Assignment::with('lesson.module')->findOrFail($data['assignment_id']);
            // Assignment → Lesson → CourseModule → Course.
            $courseId = $assignment->lesson?->module?->course_id;
            if (!$courseId) {
                throw new \DomainException('Assignment is not attached to a valid course.');
            }
            $this->guardCourseAccess($author, $courseId);
            $data['course_id'] = $courseId;
        } elseif (!empty($data['course_id'])) {
            $this->guardCourseAccess($author, $data['course_id']);
        }

        $thread = DB::transaction(function () use ($author, $category, $data) {
            $thread = ForumThread::create([
                'category_id' => $category->id,
                'author_id' => $author->id,
                'course_id' => $data['course_id'] ?? null,
                'assignment_id' => $data['assignment_id'] ?? null,
                'title' => trim($data['title']),
                'body' => trim($data['body']),
                'tags' => $data['tags'] ?? null,
            ]);

            // Auto-subscribe author
            ForumSubscription::firstOrCreate([
                'user_id' => $author->id,
                'thread_id' => $thread->id,
            ]);

            $mentioned = $this->mentions->extractAndPersist($thread, 'thread', $thread->body, $author);
            $thread->setRelation('_mentioned', $mentioned);

            return $thread;
        });

        // Notifications post-commit
        $mentioned = $thread->getRelation('_mentioned');
        if ($mentioned && $mentioned->isNotEmpty()) {
            $this->notifications->notifyMentions($thread, null, $mentioned, $author);
        }
        return $thread;
    }

    public function update(ForumThread $thread, User $actor, array $data): ForumThread
    {
        // Only the author OR a moderator/admin can edit.
        $isModerator = $actor->hasAnyRole(['system_admin', 'trainer']);
        if ((int) $thread->author_id !== (int) $actor->id && !$isModerator) {
            throw new \DomainException('You cannot edit this thread.');
        }
        $thread->fill(array_intersect_key($data, array_flip(['title', 'body', 'tags'])));
        $thread->save();
        return $thread->fresh();
    }

    public function moderate(ForumThread $thread, User $actor, array $data): ForumThread
    {
        if (!$actor->hasAnyRole(['system_admin', 'trainer'])) {
            throw new \DomainException('Only moderators may change thread state.');
        }
        $willHide = array_key_exists('is_hidden', $data) && $data['is_hidden'];

        $thread->fill(array_intersect_key($data, array_flip([
            'is_pinned', 'is_locked', 'is_hidden', 'moderation_note',
        ])));
        $thread->moderated_by = $actor->id;
        $thread->moderated_at = now();
        $thread->save();

        // AUDIT-J: auto-resolve open reports on this thread — moderator action taken.
        if ($willHide) {
            \App\Models\Forum\ForumReport::where('reportable_type', 'thread')
                ->where('reportable_id', $thread->id)
                ->where('status', 'open')
                ->update([
                    'status' => 'resolved',
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                    'resolution_note' => 'Auto-resolved: thread hidden by moderator.',
                ]);
        }
        return $thread->fresh();
    }

    public function acceptAnswer(ForumThread $thread, ForumPost $post, User $actor): ForumThread
    {
        if (!$thread->category?->supports_accepted_answer) {
            throw new \DomainException('This category does not support accepted answers.');
        }
        if ((int) $post->thread_id !== (int) $thread->id) {
            throw new \DomainException('Post does not belong to this thread.');
        }
        $isModerator = $actor->hasAnyRole(['system_admin', 'trainer']);
        if ((int) $thread->author_id !== (int) $actor->id && !$isModerator) {
            throw new \DomainException('Only the thread author or an instructor can accept an answer.');
        }

        $result = DB::transaction(function () use ($thread, $post) {
            // Clear any previously accepted post first.
            ForumPost::where('thread_id', $thread->id)
                ->where('is_accepted_answer', true)
                ->update(['is_accepted_answer' => false]);

            $post->forceFill(['is_accepted_answer' => true])->save();
            $thread->forceFill(['accepted_post_id' => $post->id])->save();
            return $thread->fresh();
        });

        $this->notifications->notifyAnswerAccepted($thread, $post, $actor);
        return $result;
    }

    private function guardCourseAccess(User $user, int $courseId): void
    {
        $course = Course::find($courseId);
        if (!$course) throw new \DomainException('Course not found.');

        // Instructor of the course, or trainer role, or admin
        if ((int) $course->instructor_id === (int) $user->id) return;
        if ($user->hasAnyRole(['system_admin', 'trainer'])) return;

        // Otherwise require an enrollment.
        $enrolled = Enrollment::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->exists();
        if (!$enrolled) {
            throw new \DomainException('You must be enrolled in this course to post here.');
        }
    }
}
