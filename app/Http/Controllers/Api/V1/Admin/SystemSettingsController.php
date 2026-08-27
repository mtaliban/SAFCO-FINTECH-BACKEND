<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * System Settings — Configure System (Admin only).
 *
 * Auth + role guard is applied at the route level (auth:sanctum, active.user, role:system_admin).
 */
class SystemSettingsController extends Controller
{
    /**
     * Mirrors the seeder defaults so individual keys can be reset without re-seeding.
     * Format: key => ['value', 'group', 'type', 'label', 'description']
     */
    private array $defaults = [
        // general
        'general.site_name' => [
            'value' => 'SAFCO FINTECH LMS',
            'group' => 'general',
            'type' => 'string',
            'label' => 'Site Name',
            'description' => null,
        ],
        'general.contact_email' => [
            'value' => 'support@safcofintech.com',
            'group' => 'general',
            'type' => 'string',
            'label' => 'Contact Email',
            'description' => null,
        ],
        'general.timezone' => [
            'value' => 'Africa/Nairobi',
            'group' => 'general',
            'type' => 'string',
            'label' => 'Default Timezone',
            'description' => null,
        ],
        'general.maintenance_mode' => [
            'value' => '0',
            'group' => 'general',
            'type' => 'boolean',
            'label' => 'Maintenance Mode',
            'description' => null,
        ],
        'general.maintenance_message' => [
            'value' => 'System is under maintenance. Please check back later.',
            'group' => 'general',
            'type' => 'string',
            'label' => 'Maintenance Message',
            'description' => null,
        ],

        // user_policy
        'user_policy.require_email_verification' => [
            'value' => '1',
            'group' => 'user_policy',
            'type' => 'boolean',
            'label' => 'Require Email Verification',
            'description' => null,
        ],
        'user_policy.auto_suspend_inactive_days' => [
            'value' => '0',
            'group' => 'user_policy',
            'type' => 'integer',
            'label' => 'Auto-suspend After Inactive Days',
            'description' => '0 = disabled',
        ],
        'user_policy.max_login_attempts' => [
            'value' => '5',
            'group' => 'user_policy',
            'type' => 'integer',
            'label' => 'Max Login Attempts Before Lock',
            'description' => null,
        ],
        'user_policy.session_timeout_minutes' => [
            'value' => '120',
            'group' => 'user_policy',
            'type' => 'integer',
            'label' => 'Session Timeout (minutes)',
            'description' => null,
        ],
        'user_policy.allow_social_login' => [
            'value' => '1',
            'group' => 'user_policy',
            'type' => 'boolean',
            'label' => 'Allow Social Login (Google/Microsoft)',
            'description' => null,
        ],

        // quiz
        'quiz.default_pass_score' => [
            'value' => '60',
            'group' => 'quiz',
            'type' => 'integer',
            'label' => 'Default Passing Score (%)',
            'description' => null,
        ],
        'quiz.default_time_limit_minutes' => [
            'value' => '0',
            'group' => 'quiz',
            'type' => 'integer',
            'label' => 'Default Time Limit (minutes)',
            'description' => '0 = no limit',
        ],
        'quiz.max_daily_attempts' => [
            'value' => '0',
            'group' => 'quiz',
            'type' => 'integer',
            'label' => 'Max Attempts Per Day',
            'description' => '0 = unlimited',
        ],
        'quiz.show_answers_after_submission' => [
            'value' => '1',
            'group' => 'quiz',
            'type' => 'boolean',
            'label' => 'Show Correct Answers After Submission',
            'description' => null,
        ],
        'quiz.allow_retakes' => [
            'value' => '1',
            'group' => 'quiz',
            'type' => 'boolean',
            'label' => 'Allow Retakes',
            'description' => null,
        ],

        // course
        'course.require_approval' => [
            'value' => '1',
            'group' => 'course',
            'type' => 'boolean',
            'label' => 'Require Admin Approval Before Publishing',
            'description' => null,
        ],
        'course.max_enrollments_per_course' => [
            'value' => '0',
            'group' => 'course',
            'type' => 'integer',
            'label' => 'Max Enrollments Per Course',
            'description' => '0 = unlimited',
        ],
        'course.allow_self_enroll' => [
            'value' => '1',
            'group' => 'course',
            'type' => 'boolean',
            'label' => 'Allow Self-Enrollment',
            'description' => null,
        ],
        'course.certificate_auto_issue' => [
            'value' => '1',
            'group' => 'course',
            'type' => 'boolean',
            'label' => 'Auto-Issue Certificate on Completion',
            'description' => null,
        ],

        // notifications
        'notifications.email_enabled' => [
            'value' => '1',
            'group' => 'notifications',
            'type' => 'boolean',
            'label' => 'Enable Email Notifications',
            'description' => null,
        ],
        'notifications.sms_enabled' => [
            'value' => '0',
            'group' => 'notifications',
            'type' => 'boolean',
            'label' => 'Enable SMS Notifications',
            'description' => null,
        ],
        'notifications.digest_frequency' => [
            'value' => 'off',
            'group' => 'notifications',
            'type' => 'string',
            'label' => 'Email Digest Frequency',
            'description' => 'daily, weekly, or off',
        ],
    ];

    /**
     * GET /api/v1/admin/settings
     * Return all settings grouped by `group`.
     */
    public function index(): JsonResponse
    {
        $settings = SystemSetting::orderBy('group')->orderBy('key')->get();

        $grouped = $settings->groupBy('group')->map(fn ($group) => $group->values());

        return $this->success($grouped);
    }

    /**
     * PUT /api/v1/admin/settings
     * Bulk-update settings.
     *
     * Request body: { "settings": { "quiz.default_pass_score": 70, ... } }
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings'   => ['required', 'array'],
            'settings.*' => ['nullable'],
        ]);

        $incoming = $request->input('settings', []);
        $updated = [];

        foreach ($incoming as $key => $value) {
            $setting = SystemSetting::where('key', $key)->first();

            if (! $setting) {
                // Only allow updating keys that are known (exist in DB)
                continue;
            }

            $setting->value = is_array($value) ? json_encode($value) : (string) $value;
            $setting->save();

            $updated[] = $setting->fresh();
        }

        $grouped = collect($updated)->groupBy('group')->map(fn ($g) => $g->values());

        return $this->success($grouped, 'Settings updated successfully.');
    }

    /**
     * POST /api/v1/admin/settings/reset
     * Reset a single setting key to its seeded default.
     *
     * Request body: { "key": "quiz.default_pass_score" }
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'key' => ['required', 'string'],
        ]);

        $key = $request->input('key');

        if (! array_key_exists($key, $this->defaults)) {
            return $this->error("No default found for key '{$key}'.", 422);
        }

        $default = $this->defaults[$key];

        $setting = SystemSetting::updateOrCreate(
            ['key' => $key],
            [
                'value'       => $default['value'],
                'group'       => $default['group'],
                'type'        => $default['type'],
                'label'       => $default['label'],
                'description' => $default['description'],
            ]
        );

        return $this->success($setting->fresh(), "Setting '{$key}' has been reset to its default value.");
    }
}
