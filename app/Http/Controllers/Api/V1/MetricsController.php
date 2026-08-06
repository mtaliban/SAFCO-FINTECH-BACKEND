<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EventOutbox;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/metrics
 * Prometheus scrape endpoint. Emits gauges and counters that Grafana
 * dashboards visualise (users, registrations, event bus health, etc).
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $lines = [];

        $lines[] = '# HELP safco_lms_users_total Total number of users';
        $lines[] = '# TYPE safco_lms_users_total gauge';
        $lines[] = 'safco_lms_users_total ' . User::count();

        $lines[] = '# HELP safco_lms_users_by_role Users grouped by role';
        $lines[] = '# TYPE safco_lms_users_by_role gauge';
        foreach (User::withCount('roles')->get()->groupBy(fn ($u) => $u->roles->first()?->name ?? 'none') as $role => $users) {
            $lines[] = sprintf('safco_lms_users_by_role{role="%s"} %d', $role, $users->count());
        }

        $lines[] = '# HELP safco_lms_users_by_provider Users grouped by auth provider';
        $lines[] = '# TYPE safco_lms_users_by_provider gauge';
        foreach (User::query()->selectRaw('auth_provider, COUNT(*) as c')->groupBy('auth_provider')->get() as $row) {
            $lines[] = sprintf('safco_lms_users_by_provider{provider="%s"} %d', $row->auth_provider, $row->c);
        }

        $lines[] = '# HELP safco_lms_users_with_2fa Users with 2FA enabled';
        $lines[] = '# TYPE safco_lms_users_with_2fa gauge';
        $lines[] = 'safco_lms_users_with_2fa ' . User::where('two_factor_enabled', true)->count();

        $lines[] = '# HELP safco_lms_login_failures_total Failed login attempts';
        $lines[] = '# TYPE safco_lms_login_failures_total counter';
        $lines[] = 'safco_lms_login_failures_total ' . LoginHistory::where('status', 'failed')->count();

        $lines[] = '# HELP safco_lms_events_pending_total Events waiting to be published';
        $lines[] = '# TYPE safco_lms_events_pending_total gauge';
        $lines[] = 'safco_lms_events_pending_total ' . EventOutbox::where('status', 'pending')->count();

        $lines[] = '# HELP safco_lms_events_published_total Events successfully published';
        $lines[] = '# TYPE safco_lms_events_published_total counter';
        $lines[] = 'safco_lms_events_published_total ' . EventOutbox::where('status', 'published')->count();

        $lines[] = '# HELP safco_lms_events_failed_total Events that failed to publish';
        $lines[] = '# TYPE safco_lms_events_failed_total counter';
        $lines[] = 'safco_lms_events_failed_total ' . EventOutbox::where('status', 'failed')->count();

        return response(
            implode("\n", $lines) . "\n",
            200,
            ['Content-Type' => 'text/plain; version=0.0.4']
        );
    }
}
