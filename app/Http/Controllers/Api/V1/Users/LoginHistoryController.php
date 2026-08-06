<?php

namespace App\Http\Controllers\Api\V1\Users;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoginHistoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginHistoryController extends Controller
{
    /**
     * GET /api/v1/users/login-history
     * Returns the current user's login history (paginated).
     */
    public function index(Request $request): JsonResponse
    {
        $history = $request->user()
            ->loginHistories()
            ->latest()
            ->paginate($request->query('per_page', 20));

        return $this->success(LoginHistoryResource::collection($history)->response()->getData(true));
    }
}
