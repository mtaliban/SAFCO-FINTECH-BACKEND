<?php

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Forum\ForumThread;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * SRS Non-Functional Requirements — Broadcasting channel authorization.
 *
 * By default a Reverb private-* channel is CLOSED unless there's a rule here.
 * These rules are called when a client tries to subscribe to a private/presence
 * channel; return true to authorize, false to reject.
 */

// User's own notification stream — bell icon subscribes here for instant pings.
Broadcast::channel('user.{userId}', function (User $user, int $userId) {
    return (int) $user->id === (int) $userId;
});

// Live quiz session — anyone enrolled in the parent course can watch.
Broadcast::channel('quiz-session.{sessionId}', function (User $user, int $sessionId) {
    $courseId = \DB::table('quiz_sessions')
        ->join('quizzes', 'quizzes.id', '=', 'quiz_sessions.quiz_id')
        ->join('course_modules', 'course_modules.id', '=', 'quizzes.module_id')
        ->where('quiz_sessions.id', $sessionId)
        ->value('course_modules.course_id');
    if (!$courseId) return false;
    // Instructor of the course or an enrolled student
    if (Course::where('id', $courseId)->where('instructor_id', $user->id)->exists()) return true;
    return Enrollment::where('user_id', $user->id)->where('course_id', $courseId)->exists();
});

// Attendance session — only the trainer running it + admins may watch.
Broadcast::channel('attendance-session.{sessionId}', function (User $user, int $sessionId) {
    if ($user->hasAnyRole(['system_admin'])) return true;
    return \DB::table('attendance_sessions')
        ->where('id', $sessionId)
        ->where('trainer_id', $user->id)
        ->exists();
});

// Forum thread — only subscribers or moderators (mostly used for typing indicators).
Broadcast::channel('forum-thread.{threadUuid}', function (User $user, string $threadUuid) {
    $thread = ForumThread::where('uuid', $threadUuid)->first();
    if (!$thread) return false;
    if ($user->hasAnyRole(['system_admin', 'trainer'])) return true;
    return \DB::table('forum_subscriptions')
        ->where('thread_id', $thread->id)
        ->where('user_id', $user->id)
        ->exists();
});
