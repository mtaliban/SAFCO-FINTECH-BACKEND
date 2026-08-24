<?php

namespace App\Services\Notifications\Templates;

use App\Models\User;

/**
 * SRS Module 15 — Message rendering.
 *
 * Each event key maps to a subject + HTML body. Payload placeholders are
 * substituted at render time. Kept in one file so a new event = one entry,
 * not a new Blade view per notification.
 *
 * Contract: render() returns ['subject' => string, 'html' => string].
 */
class NotificationTemplate
{
    public static function render(string $eventKey, User $user, array $payload): array
    {
        $name = $user->profile?->first_name ?? explode('@', $user->email)[0];
        $appName = config('app.name', 'SAFCO FINTECH LMS');
        $appUrl = config('app.url', 'https://safcofintech.co.tz');

        $t = match ($eventKey) {
            'account.welcome' => [
                'subject' => "Karibu {$appName}, {$name}!",
                'body' => "Habari {$name},\n\nKaribu {$appName} — jukwaa la mafunzo bora kwa Excel, Financial Modeling, na Corporate Training.\n\nUnaweza kuanza kwa kuvinjari kozi zetu au kujaza profile yako.\n\nAsante!",
            ],
            'account.email_verified' => [
                'subject' => 'Email yako imehakikishwa',
                'body' => "Habari {$name},\n\nEmail yako imehakikishwa. Sasa unaweza kutumia huduma zote za {$appName}.",
            ],
            'account.password_reset' => [
                'subject' => 'Ombi la ku-reset password',
                'body' => "Habari {$name},\n\nTumeombewa ku-reset password yako. Kama hukuomba, puuza email hii.\n\nLink: " . ($payload['reset_url'] ?? '(link haipo)') . "\n\nLink itakwisha baada ya saa 1.",
            ],
            'account.security_alert' => [
                'subject' => "\u{26A0} Security alert — {$appName}",
                'body' => "Habari {$name},\n\nTumegundua shughuli mpya kwenye account yako:\n\n" . ($payload['event'] ?? 'Suspicious sign-in detected') . "\n\nIP: " . ($payload['ip'] ?? 'unknown') . "\nWakati: " . ($payload['at'] ?? now()->toDateTimeString()) . "\n\nKama sio wewe, badilisha password mara moja.",
            ],
            'course.enrolled' => [
                'subject' => "Enrollment confirmed: " . ($payload['course_title'] ?? 'course'),
                'body' => "Habari {$name},\n\nUmesajiliwa kwenye kozi: " . ($payload['course_title'] ?? '—') . "\n\nUnaweza kuanza somo la kwanza sasa hivi.",
            ],
            'course.completed' => [
                'subject' => "Umemaliza kozi: " . ($payload['course_title'] ?? 'course'),
                'body' => "Hongera {$name}!\n\nUmemaliza masomo yote ya kozi \"" . ($payload['course_title'] ?? '—') . "\". Cheti chako kitakuja hivi karibuni.",
            ],
            'certificate.issued' => [
                'subject' => 'Cheti chako kiko tayari',
                'body' => "Habari {$name},\n\nCheti chako kimezalishwa. Unaweza kukipakua kutoka: " . ($payload['download_url'] ?? '/student/certificates'),
            ],
            'quiz.reminder' => [
                'subject' => 'Kumbukumbu ya quiz',
                'body' => "Habari {$name},\n\nQuiz \"" . ($payload['quiz_title'] ?? 'yako') . "\" inaanza baada ya dakika " . ($payload['minutes_to_start'] ?? '?') . ".",
            ],
            'quiz.result' => [
                'subject' => 'Matokeo ya quiz yako yamerudi',
                'body' => "Habari {$name},\n\nMatokeo ya quiz \"" . ($payload['quiz_title'] ?? '—') . "\": " . ($payload['score'] ?? '?') . '/' . ($payload['max_score'] ?? '?') . " (" . ($payload['result'] ?? '') . ")",
            ],
            'assignment.graded' => [
                'subject' => 'Assignment yako imegradiwa',
                'body' => "Habari {$name},\n\nAssignment \"" . ($payload['assignment_title'] ?? '—') . "\" imegradiwa: " . ($payload['grade'] ?? '?') . '/' . ($payload['max_points'] ?? '?'),
            ],
            'assignment.due_soon' => [
                'subject' => 'Assignment inakaribia deadline',
                'body' => "Habari {$name},\n\nAssignment \"" . ($payload['assignment_title'] ?? '—') . "\" inaisha muda baada ya " . ($payload['hours_left'] ?? '?') . " hours.",
            ],
            'payment.received' => [
                'subject' => 'Payment received — TZS ' . number_format($payload['amount'] ?? 0),
                'body' => "Habari {$name},\n\nTumepokea malipo yako TZS " . number_format($payload['amount'] ?? 0) . " kwa " . ($payload['description'] ?? '—') . ".\n\nAsante!",
            ],
            'payment.failed' => [
                'subject' => 'Payment failed',
                'body' => "Habari {$name},\n\nMalipo yako ya TZS " . number_format($payload['amount'] ?? 0) . " yamekataliwa.\n\nSababu: " . ($payload['reason'] ?? '—') . "\n\nUnaweza kujaribu tena.",
            ],
            'payment.refunded' => [
                'subject' => 'Refund issued — TZS ' . number_format($payload['amount'] ?? 0),
                'body' => "Habari {$name},\n\nRefund ya TZS " . number_format($payload['amount'] ?? 0) . " imetolewa. Fedha zitarudi kwako baada ya siku 3-7.",
            ],
            'invoice.issued' => [
                'subject' => 'Invoice mpya — TZS ' . number_format($payload['amount'] ?? 0),
                'body' => "Habari {$name},\n\nInvoice mpya \"" . ($payload['description'] ?? '—') . "\" TZS " . number_format($payload['amount'] ?? 0) . " iko tayari. Lipa hapa: " . ($payload['pay_url'] ?? '/billing'),
            ],
            'forum.reply' => [
                'subject' => 'Reply mpya: ' . ($payload['thread_title'] ?? 'thread yako'),
                'body' => ($payload['actor_name'] ?? 'Mtu') . " ame-reply kwenye \"" . ($payload['thread_title'] ?? '—') . "\":\n\n" . ($payload['excerpt'] ?? ''),
            ],
            'forum.mention' => [
                'subject' => 'Umetajwa kwenye discussion',
                'body' => ($payload['actor_name'] ?? 'Mtu') . " ame-mention wewe: \"" . ($payload['excerpt'] ?? '') . "\"",
            ],
            'forum.answer_accepted' => [
                'subject' => 'Answer yako imekubaliwa!',
                'body' => "Hongera {$name}! Answer yako kwenye \"" . ($payload['thread_title'] ?? '—') . "\" imekubaliwa.",
            ],
            'trainer.credential_verified' => [
                'subject' => 'Credential imethibitika',
                'body' => "Habari {$name},\n\n" . ($payload['credential_type'] ?? 'Credential') . " \"" . ($payload['credential_name'] ?? '—') . "\" imethibitika. Uko karibu na 'Certified Trainer' badge.",
            ],
            'trainer.credential_rejected' => [
                'subject' => 'Credential submission imekataliwa',
                'body' => "Habari {$name},\n\n" . ($payload['credential_type'] ?? 'Credential') . " \"" . ($payload['credential_name'] ?? '—') . "\" imekataliwa.\n\nSababu: " . ($payload['reason'] ?? '—'),
            ],
            'trainer.cert_expiring' => [
                'subject' => 'Certification zako zinakaribia kuisha muda',
                'body' => "Habari {$name},\n\n" . ($payload['count'] ?? '?') . " kwenye certifications zako zinaisha muda baada ya siku " . ($payload['days'] ?? '60') . " au chini.",
            ],
            'system.announcement' => [
                'subject' => $payload['title'] ?? "Announcement kutoka {$appName}",
                'body' => $payload['body'] ?? '',
            ],
            default => [
                'subject' => $appName,
                'body' => 'Notification: ' . $eventKey,
            ],
        };

        return [
            'subject' => $t['subject'],
            'html' => self::wrapHtml($t['subject'], $t['body'], $appName, $appUrl, $payload),
        ];
    }

    private static function wrapHtml(string $subject, string $body, string $appName, string $appUrl, array $payload): string
    {
        $safeBody = nl2br(e($body));
        $ctaHtml = '';
        if (!empty($payload['action_url']) && !empty($payload['action_label'])) {
            $url = e($payload['action_url']);
            $label = e($payload['action_label']);
            $ctaHtml = <<<HTML
                <p style="text-align:center;margin:24px 0;">
                  <a href="{$url}" style="background:#f97316;color:white;padding:12px 24px;
                     border-radius:6px;text-decoration:none;font-weight:600;display:inline-block;">
                     {$label}
                  </a>
                </p>
HTML;
        }
        $year = date('Y');
        return <<<HTML
            <!doctype html>
            <html><body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f4f4f7;">
              <div style="max-width:600px;margin:0 auto;background:white;">
                <div style="padding:24px;background:#0f172a;color:white;">
                  <h1 style="margin:0;font-size:22px;">{$appName}</h1>
                </div>
                <div style="padding:32px;color:#334155;line-height:1.6;">
                  <h2 style="color:#0f172a;font-size:20px;margin-top:0;">{$subject}</h2>
                  <p>{$safeBody}</p>
                  {$ctaHtml}
                </div>
                <div style="padding:16px 32px;background:#f8fafc;color:#64748b;font-size:12px;text-align:center;">
                  &copy; {$year} {$appName}. Kama hutaki emails hizi, badilisha kwenye Settings → Notifications.
                </div>
              </div>
            </body></html>
HTML;
    }
}
