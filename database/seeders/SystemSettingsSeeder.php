<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // ── general ──────────────────────────────────────────────────────
            [
                'key'         => 'general.site_name',
                'value'       => 'SAFCO FINTECH LMS',
                'group'       => 'general',
                'type'        => 'string',
                'label'       => 'Site Name',
                'description' => null,
            ],
            [
                'key'         => 'general.contact_email',
                'value'       => 'support@safcofintech.com',
                'group'       => 'general',
                'type'        => 'string',
                'label'       => 'Contact Email',
                'description' => null,
            ],
            [
                'key'         => 'general.timezone',
                'value'       => 'Africa/Nairobi',
                'group'       => 'general',
                'type'        => 'string',
                'label'       => 'Default Timezone',
                'description' => null,
            ],
            [
                'key'         => 'general.maintenance_mode',
                'value'       => '0',
                'group'       => 'general',
                'type'        => 'boolean',
                'label'       => 'Maintenance Mode',
                'description' => null,
            ],
            [
                'key'         => 'general.maintenance_message',
                'value'       => 'System is under maintenance. Please check back later.',
                'group'       => 'general',
                'type'        => 'string',
                'label'       => 'Maintenance Message',
                'description' => null,
            ],

            // ── user_policy ───────────────────────────────────────────────────
            [
                'key'         => 'user_policy.require_email_verification',
                'value'       => '1',
                'group'       => 'user_policy',
                'type'        => 'boolean',
                'label'       => 'Require Email Verification',
                'description' => null,
            ],
            [
                'key'         => 'user_policy.auto_suspend_inactive_days',
                'value'       => '0',
                'group'       => 'user_policy',
                'type'        => 'integer',
                'label'       => 'Auto-suspend After Inactive Days',
                'description' => '0 = disabled',
            ],
            [
                'key'         => 'user_policy.max_login_attempts',
                'value'       => '5',
                'group'       => 'user_policy',
                'type'        => 'integer',
                'label'       => 'Max Login Attempts Before Lock',
                'description' => null,
            ],
            [
                'key'         => 'user_policy.session_timeout_minutes',
                'value'       => '120',
                'group'       => 'user_policy',
                'type'        => 'integer',
                'label'       => 'Session Timeout (minutes)',
                'description' => null,
            ],
            [
                'key'         => 'user_policy.allow_social_login',
                'value'       => '1',
                'group'       => 'user_policy',
                'type'        => 'boolean',
                'label'       => 'Allow Social Login (Google/Microsoft)',
                'description' => null,
            ],

            // ── quiz ──────────────────────────────────────────────────────────
            [
                'key'         => 'quiz.default_pass_score',
                'value'       => '60',
                'group'       => 'quiz',
                'type'        => 'integer',
                'label'       => 'Default Passing Score (%)',
                'description' => null,
            ],
            [
                'key'         => 'quiz.default_time_limit_minutes',
                'value'       => '0',
                'group'       => 'quiz',
                'type'        => 'integer',
                'label'       => 'Default Time Limit (minutes)',
                'description' => '0 = no limit',
            ],
            [
                'key'         => 'quiz.max_daily_attempts',
                'value'       => '0',
                'group'       => 'quiz',
                'type'        => 'integer',
                'label'       => 'Max Attempts Per Day',
                'description' => '0 = unlimited',
            ],
            [
                'key'         => 'quiz.show_answers_after_submission',
                'value'       => '1',
                'group'       => 'quiz',
                'type'        => 'boolean',
                'label'       => 'Show Correct Answers After Submission',
                'description' => null,
            ],
            [
                'key'         => 'quiz.allow_retakes',
                'value'       => '1',
                'group'       => 'quiz',
                'type'        => 'boolean',
                'label'       => 'Allow Retakes',
                'description' => null,
            ],

            // ── course ────────────────────────────────────────────────────────
            [
                'key'         => 'course.require_approval',
                'value'       => '1',
                'group'       => 'course',
                'type'        => 'boolean',
                'label'       => 'Require Admin Approval Before Publishing',
                'description' => null,
            ],
            [
                'key'         => 'course.max_enrollments_per_course',
                'value'       => '0',
                'group'       => 'course',
                'type'        => 'integer',
                'label'       => 'Max Enrollments Per Course',
                'description' => '0 = unlimited',
            ],
            [
                'key'         => 'course.allow_self_enroll',
                'value'       => '1',
                'group'       => 'course',
                'type'        => 'boolean',
                'label'       => 'Allow Self-Enrollment',
                'description' => null,
            ],
            [
                'key'         => 'course.certificate_auto_issue',
                'value'       => '1',
                'group'       => 'course',
                'type'        => 'boolean',
                'label'       => 'Auto-Issue Certificate on Completion',
                'description' => null,
            ],

            // ── notifications ─────────────────────────────────────────────────
            [
                'key'         => 'notifications.email_enabled',
                'value'       => '1',
                'group'       => 'notifications',
                'type'        => 'boolean',
                'label'       => 'Enable Email Notifications',
                'description' => null,
            ],
            [
                'key'         => 'notifications.sms_enabled',
                'value'       => '0',
                'group'       => 'notifications',
                'type'        => 'boolean',
                'label'       => 'Enable SMS Notifications',
                'description' => null,
            ],
            [
                'key'         => 'notifications.digest_frequency',
                'value'       => 'off',
                'group'       => 'notifications',
                'type'        => 'string',
                'label'       => 'Email Digest Frequency',
                'description' => 'daily, weekly, or off',
            ],
        ];

        foreach ($settings as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value'       => $setting['value'],
                    'group'       => $setting['group'],
                    'type'        => $setting['type'],
                    'label'       => $setting['label'],
                    'description' => $setting['description'],
                ]
            );
        }
    }
}
