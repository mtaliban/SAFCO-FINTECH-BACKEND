<?php

namespace App\Notifications;

use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumThread;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * SRS Module 14 — one notification class for all forum events.
 *
 * Types:
 *   'reply'          — new reply on your thread
 *   'mention'        — you were @mentioned
 *   'answer_accepted'— your answer was accepted
 *   'subscription'   — new activity on a thread you're subscribed to
 *
 * Stored via the database channel so the frontend can list them; mail is
 * optional per user preference (kept as an available channel).
 */
class ForumEventNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $eventType,
        public readonly ForumThread $thread,
        public readonly ?ForumPost $post,
        public readonly ?User $actor,
    ) {}

    public function via($notifiable): array
    {
        // Default to database only; mail can be toggled per user later.
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => $this->eventType,
            'thread_uuid' => $this->thread->uuid,
            'thread_title' => $this->thread->title,
            'post_uuid' => $this->post?->uuid,
            'actor_name' => $this->actor?->profile?->full_name ?? $this->actor?->email,
            'actor_uuid' => $this->actor?->uuid,
            'excerpt' => $this->post
                ? \Str::limit(strip_tags($this->post->body), 140)
                : \Str::limit(strip_tags($this->thread->body), 140),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $subject = match ($this->eventType) {
            'reply' => 'New reply on "' . $this->thread->title . '"',
            'mention' => 'You were mentioned in "' . $this->thread->title . '"',
            'answer_accepted' => 'Your answer was accepted',
            default => 'New activity on "' . $this->thread->title . '"',
        };
        return (new MailMessage)
            ->subject($subject)
            ->line($subject)
            ->action('View discussion', url('/forum/threads/' . $this->thread->uuid));
    }
}
