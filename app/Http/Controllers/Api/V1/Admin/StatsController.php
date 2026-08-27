<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\LoginHistory;
use App\Models\Organization;
use App\Models\Quiz;
use App\Models\QuizSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * High-level system stats for the Admin dashboard (SRS 3.1 "Generate reports").
 */
class StatsController extends Controller
{
    /** GET /api/v1/admin/stats */
    public function index(Request $request): JsonResponse
    {
        $from = $request->get('date_from');
        $to   = $request->get('date_to');

        // Revenue (paid invoices)
        $revQ = Invoice::where('status', 'paid');
        if ($from) $revQ->whereDate('paid_at', '>=', $from);
        if ($to)   $revQ->whereDate('paid_at', '<=', $to);

        $revenueTotal  = (int) $revQ->sum('total_tzs');
        $revenuePaidCount = (int) $revQ->count();

        // Monthly revenue (last 6 months) — grouped in PHP so it works on MySQL and SQLite (tests)
        $monthlyRevenue = Invoice::where('status', 'paid')
            ->where('paid_at', '>=', now()->subMonths(6)->startOfMonth())
            ->get(['paid_at', 'total_tzs'])
            ->groupBy(fn ($inv) => $inv->paid_at->format('Y-m'))
            ->map(fn ($group, $month) => [
                'month'     => $month,
                'total_tzs' => (int) $group->sum('total_tzs'),
                'count'     => $group->count(),
            ])
            ->sortKeys()
            ->values();

        return $this->success([
            'users' => [
                'total'   => User::count(),
                'active'  => User::where('status', 'active')->count(),
                'pending' => User::where('status', 'pending')->count(),
                'by_role' => DB::table('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->groupBy('roles.name')
                    ->selectRaw('roles.name as role, COUNT(DISTINCT model_has_roles.model_id) as count')
                    ->pluck('count', 'role'),
            ],
            'quizzes' => [
                'total'     => Quiz::count(),
                'published' => Quiz::where('status', 'published')->count(),
                'draft'     => Quiz::where('status', 'draft')->count(),
            ],
            'courses' => [
                'total'           => Course::count(),
                'published'       => Course::where('status', 'published')->count(),
                'pending_approval'=> Course::where('status', 'pending_approval')->count(),
                'draft'           => Course::where('status', 'draft')->count(),
            ],
            'enrollments' => [
                'total'     => Enrollment::count(),
                'active'    => Enrollment::whereNull('completed_at')->count(),
                'completed' => Enrollment::whereNotNull('completed_at')->count(),
            ],
            'certificates' => [
                'total'   => Certificate::count(),
                'revoked' => Certificate::where('status', 'revoked')->count(),
            ],
            'sessions' => [
                'total'     => QuizSession::count(),
                'active'    => QuizSession::whereIn('status', ['waiting', 'question_active', 'question_ended'])->count(),
                'completed' => QuizSession::where('status', 'completed')->count(),
            ],
            'organizations'     => Organization::count(),
            'recent_logins_24h' => LoginHistory::where('created_at', '>=', now()->subDay())->count(),
            'revenue' => [
                'total_tzs'        => $revenueTotal,
                'paid_invoices'    => $revenuePaidCount,
                'pending_invoices' => Invoice::where('status', 'issued')->count(),
                'monthly'          => $monthlyRevenue,
            ],
        ]);
    }
}
