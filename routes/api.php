<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\Quiz\LiveQuizController;
use App\Http\Controllers\Api\V1\Quiz\QuestionBankController;
use App\Http\Controllers\Api\V1\Quiz\QuizController;
use App\Http\Controllers\Api\V1\Users\LoginHistoryController;
use App\Http\Controllers\Api\V1\Users\UserController;
use App\Http\Controllers\Api\V1\Users\UserProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes - SAFCO FINTECH LMS
|--------------------------------------------------------------------------
| All routes are prefixed with /api/v1 (configured in bootstrap/app.php)
| Mobile & web clients hit the SAME endpoints — no separate mobile API.
*/

Route::prefix('v1')->group(function () {

    /* ============================================================
     * PUBLIC AUTH ENDPOINTS (no token required)
     * ============================================================ */
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('register', RegisterController::class)->name('auth.register');
        Route::post('login', [LoginController::class, 'login'])->name('auth.login');

        // Password reset
        Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->name('auth.password.forgot');
        Route::post('password/reset', [PasswordResetController::class, 'reset'])->name('auth.password.reset');

        // OTP
        Route::post('otp/request', [OtpController::class, 'request'])->middleware('throttle:otp')->name('auth.otp.request');
        Route::post('otp/verify', [OtpController::class, 'verify'])->name('auth.otp.verify');

        // Social auth
        Route::get('social/{provider}', [SocialAuthController::class, 'redirect'])
            ->whereIn('provider', ['google', 'microsoft'])
            ->name('auth.social.redirect');
        Route::get('social/{provider}/callback', [SocialAuthController::class, 'callback'])
            ->whereIn('provider', ['google', 'microsoft'])
            ->name('auth.social.callback');
    });

    /* ============================================================
     * AUTHENTICATED ENDPOINTS (Sanctum required)
     * ============================================================ */
    Route::middleware(['auth:sanctum', 'active.user'])->group(function () {

        // Session management
        Route::prefix('auth')->group(function () {
            Route::post('logout', [LoginController::class, 'logout'])->name('auth.logout');
            Route::get('me', [LoginController::class, 'me'])->name('auth.me');

            // Two-Factor Authentication
            Route::prefix('2fa')->group(function () {
                Route::post('setup', [TwoFactorController::class, 'setup'])->name('auth.2fa.setup');
                Route::post('confirm', [TwoFactorController::class, 'confirm'])->name('auth.2fa.confirm');
                Route::post('challenge', [TwoFactorController::class, 'challenge'])->name('auth.2fa.challenge');
                Route::delete('/', [TwoFactorController::class, 'disable'])->name('auth.2fa.disable');
            });
        });

        // User profile
        Route::prefix('users')->group(function () {
            Route::get('profile', [UserProfileController::class, 'show'])->name('users.profile.show');
            Route::patch('profile', [UserProfileController::class, 'update'])->name('users.profile.update');
            Route::post('profile/picture', [UserProfileController::class, 'uploadPicture'])->name('users.profile.picture');
            Route::get('login-history', [LoginHistoryController::class, 'index'])->name('users.login-history');
        });

        // Admin-only: user management
        Route::prefix('admin/users')->middleware('role:system_admin')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('admin.users.index');
            Route::get('{user:uuid}', [UserController::class, 'show'])->name('admin.users.show');
            Route::patch('{user:uuid}/status', [UserController::class, 'updateStatus'])->name('admin.users.status');
            Route::delete('{user:uuid}', [UserController::class, 'destroy'])->name('admin.users.destroy');
        });
    });

    /* ============================================================
     * HEALTH & METRICS (public)
     * ============================================================ */
    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'version' => config('app.version', '1.0.0'),
        'timestamp' => now()->toIso8601String(),
    ]))->name('health');

    Route::get('metrics', \App\Http\Controllers\Api\V1\MetricsController::class)->name('metrics');

    /* ============================================================
     * PUBLIC PLAY ENDPOINTS - Students join Kahoot without account
     * ============================================================ */
    Route::prefix('play')->group(function () {
        Route::post('join', [LiveQuizController::class, 'playJoin'])->name('play.join');
        Route::get('session/{pin}', [LiveQuizController::class, 'playSessionState'])->name('play.session.state');
        Route::post('session/{pin}/answer', [LiveQuizController::class, 'playSubmitAnswer'])->name('play.session.answer');
    });

    /* ============================================================
     * QUIZ MODULE - Trainer / Admin endpoints (auth required)
     * ============================================================ */
    Route::middleware(['auth:sanctum', 'active.user'])->group(function () {

        // Question Banks + Questions
        Route::apiResource('question-banks', QuestionBankController::class)
            ->only(['index', 'store', 'show'])
            ->parameters(['question-banks' => 'questionBank']);
        Route::post('question-banks/{questionBank:uuid}/questions', [QuestionBankController::class, 'addQuestion'])
            ->name('question-banks.questions.store');
        Route::patch('questions/{question:uuid}', [QuestionBankController::class, 'updateQuestion'])
            ->name('questions.update');
        Route::delete('questions/{question:uuid}', [QuestionBankController::class, 'deleteQuestion'])
            ->name('questions.destroy');

        // Quizzes
        Route::apiResource('quizzes', QuizController::class)
            ->parameters(['quizzes' => 'quiz']);
        Route::post('quizzes/{quiz:uuid}/questions/sync', [QuizController::class, 'syncQuestions'])
            ->name('quizzes.questions.sync');
        Route::post('quizzes/{quiz:uuid}/publish', [QuizController::class, 'publish'])
            ->name('quizzes.publish');

        // Live host controls
        Route::prefix('quizzes/{quiz:uuid}')->group(function () {
            Route::post('host', [LiveQuizController::class, 'hostStart'])->name('quizzes.host.start');
        });

        Route::prefix('sessions/{session:uuid}')->group(function () {
            Route::post('start-question', [LiveQuizController::class, 'hostStartQuestion'])->name('sessions.question.start');
            Route::post('end-question', [LiveQuizController::class, 'hostEndQuestion'])->name('sessions.question.end');
            Route::post('complete', [LiveQuizController::class, 'hostComplete'])->name('sessions.complete');
            Route::get('leaderboard', [LiveQuizController::class, 'leaderboard'])->name('sessions.leaderboard');
        });
    });
});
