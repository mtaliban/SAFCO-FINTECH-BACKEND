<?php

namespace App\Services\Notifications;

/**
 * SRS Module 15 — Central registry of every notification event the app can fire.
 *
 * Each event has:
 *  - key: stable machine ID (used in DB prefs; NEVER rename after release)
 *  - label: human-readable name for /settings/notifications
 *  - description: what triggers it
 *  - default_channels: channels enabled by default when the user has no pref row
 *  - critical: if true, prefs are IGNORED — always sent (security-critical items)
 *  - category: groups events on the settings UI
 */
class EventCatalog
{
    public const CATEGORIES = [
        'account' => 'Account & Security',
        'learning' => 'Courses & Learning',
        'assessments' => 'Quizzes & Assignments',
        'payments' => 'Payments & Billing',
        'forum' => 'Discussion Forum',
        'trainer' => 'Trainer Portal',
        'system' => 'System Announcements',
    ];

    /**
     * @return array<string, array{
     *   label:string, description:string, category:string,
     *   default_channels:array, critical:bool
     * }>
     */
    public static function all(): array
    {
        return [
            // Account & Security
            'account.welcome' => [
                'label' => 'Welcome to SAFCO',
                'description' => 'Sent when you first sign up.',
                'category' => 'account',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
            'account.email_verified' => [
                'label' => 'Email verified',
                'description' => 'Confirmation once you verify your email address.',
                'category' => 'account',
                'default_channels' => ['in_app'],
                'critical' => false,
            ],
            'account.password_reset' => [
                'label' => 'Password reset requested',
                'description' => 'Sent when someone requests a password reset for your account.',
                'category' => 'account',
                'default_channels' => ['email'],
                'critical' => true,  // Always sent — security-critical
            ],
            'account.security_alert' => [
                'label' => 'Security alert',
                'description' => 'Suspicious sign-in, 2FA changes, or new device.',
                'category' => 'account',
                'default_channels' => ['email', 'in_app'],
                'critical' => true,
            ],

            // Courses & Learning
            'course.enrolled' => [
                'label' => 'Enrollment confirmed',
                'description' => 'Sent when you enroll in a course.',
                'category' => 'learning',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
            'course.completed' => [
                'label' => 'Course completed',
                'description' => 'Sent when you finish all lessons in a course.',
                'category' => 'learning',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
            'certificate.issued' => [
                'label' => 'Certificate issued',
                'description' => 'Sent when a new certificate is available for download.',
                'category' => 'learning',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],

            // Assessments
            'quiz.reminder' => [
                'label' => 'Quiz reminder',
                'description' => 'A scheduled quiz is starting soon.',
                'category' => 'assessments',
                'default_channels' => ['in_app'],
                'critical' => false,
            ],
            'quiz.result' => [
                'label' => 'Quiz result available',
                'description' => 'Sent when a quiz is graded.',
                'category' => 'assessments',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
            'assignment.graded' => [
                'label' => 'Assignment graded',
                'description' => 'Sent when a trainer grades your submission.',
                'category' => 'assessments',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
            'assignment.due_soon' => [
                'label' => 'Assignment due soon',
                'description' => 'Reminder 24 hours before an assignment deadline.',
                'category' => 'assessments',
                'default_channels' => ['in_app'],
                'critical' => false,
            ],

            // Payments
            'payment.received' => [
                'label' => 'Payment received',
                'description' => 'Sent when a payment successfully clears.',
                'category' => 'payments',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
            'payment.failed' => [
                'label' => 'Payment failed',
                'description' => 'Sent if a payment fails to clear.',
                'category' => 'payments',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
            'payment.refunded' => [
                'label' => 'Refund issued',
                'description' => 'Sent when a refund is processed.',
                'category' => 'payments',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
            'invoice.issued' => [
                'label' => 'New invoice',
                'description' => 'Sent when a new invoice is created for you.',
                'category' => 'payments',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],

            // Forum
            'forum.reply' => [
                'label' => 'Reply on your thread',
                'description' => 'Someone replied to a discussion you started or subscribed to.',
                'category' => 'forum',
                'default_channels' => ['in_app'],
                'critical' => false,
            ],
            'forum.mention' => [
                'label' => '@Mention',
                'description' => 'Someone mentioned you in a forum post.',
                'category' => 'forum',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
            'forum.answer_accepted' => [
                'label' => 'Your answer was accepted',
                'description' => 'The thread author marked your answer as accepted.',
                'category' => 'forum',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],

            // Trainer Portal
            'trainer.credential_verified' => [
                'label' => 'Credential verified',
                'description' => 'An admin verified one of your qualifications/certifications.',
                'category' => 'trainer',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
            'trainer.credential_rejected' => [
                'label' => 'Credential rejected',
                'description' => 'An admin rejected one of your credential submissions.',
                'category' => 'trainer',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
            'trainer.cert_expiring' => [
                'label' => 'Certification expiring soon',
                'description' => 'One of your certifications expires within 60 days.',
                'category' => 'trainer',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],

            // System
            'system.announcement' => [
                'label' => 'System announcements',
                'description' => 'General updates and news from SAFCO admins.',
                'category' => 'system',
                'default_channels' => ['email', 'in_app'],
                'critical' => false,
            ],
        ];
    }

    public static function meta(string $eventKey): ?array
    {
        return self::all()[$eventKey] ?? null;
    }

    public static function isCritical(string $eventKey): bool
    {
        return (bool) (self::meta($eventKey)['critical'] ?? false);
    }

    public static function defaultChannels(string $eventKey): array
    {
        return (array) (self::meta($eventKey)['default_channels'] ?? ['in_app']);
    }

    /** All channel keys the app recognises. */
    public static function allChannels(): array
    {
        return ['email', 'in_app', 'whatsapp', 'push', 'sms'];
    }

    /** Channels currently ENABLED at the app level (not user-level). */
    public static function activeChannels(): array
    {
        // WhatsApp / push / sms are stubs today — accepted at the API but not
        // actually delivering. Marked as future.
        return ['email', 'in_app'];
    }
}
