<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\Organization;
use App\Models\Quiz;
use App\Models\QuizSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * High-level system stats for the Admin dashboard (SRS 3.1 "Generate reports").
 */
class StatsController extends Controller
{
    /** GET /api/v1/admin/stats */
    public function index(): JsonResponse
    {
        return $this->success([
            'users' => [
                'total' => User::count(),
                'active' => User::where('status', 'active')->count(),
                'pending' => User::where('status', 'pending')->count(),
                'by_role' => DB::table('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->groupBy('roles.name')
                    ->selectRaw('roles.name as role, COUNT(DISTINCT model_has_roles.model_id) as count')
                    ->pluck('count', 'role'),
            ],
            'quizzes' => [
                'total' => Quiz::count(),
                'published' => Quiz::where('status', 'published')->count(),
                'draft' => Quiz::where('status', 'draft')->count(),
            ],
            'sessions' => [
                'total' => QuizSession::count(),
                'active' => QuizSession::whereIn('status', ['waiting', 'question_active', 'question_ended'])->count(),
                'completed' => QuizSession::where('status', 'completed')->count(),
            ],
            'organizations' => Organization::count(),
            'recent_logins_24h' => LoginHistory::where('created_at', '>=', now()->subDay())->count(),
        ]);
    }
}
