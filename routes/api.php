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
    Route::prefix('play')->middleware('throttle:play')->group(function () {
        Route::post('join', [LiveQuizController::class, 'playJoin'])->name('play.join');
        Route::get('session/{pin}', [LiveQuizController::class, 'playSessionState'])->name('play.session.state');
        Route::get('session/{pin}/current-question', [LiveQuizController::class, 'playCurrentQuestion'])->name('play.session.current-question');
        Route::post('session/{pin}/answer', [LiveQuizController::class, 'playSubmitAnswer'])->name('play.session.answer');
    });

    /* ============================================================
     * PUBLIC CERTIFICATE VERIFICATION (SRS Module 10, no auth)
     * Rate-limited to prevent enumeration of valid cert numbers.
     * ============================================================ */
    Route::prefix('verify')->middleware('throttle:30,1')->group(function () {
        Route::get('certificate/{certNumber}', [\App\Http\Controllers\Api\V1\Certificate\CertificateVerificationController::class, 'verify'])
            ->where('certNumber', 'SAFCO-[0-9]{4}-[A-Z0-9]{4,16}')
            ->name('verify.certificate');
        Route::post('search', [\App\Http\Controllers\Api\V1\Certificate\CertificateVerificationController::class, 'search'])->name('verify.search');
    });

    /* ============================================================
     * QUIZ MODULE — Trainer / System Admin only (per SRS 3.2)
     * Facilitator can host (help run) but not create/delete.
     * ============================================================ */
    Route::middleware(['auth:sanctum', 'active.user', 'role:trainer|system_admin'])->group(function () {

        // Question Banks + Questions (create/edit/delete)
        Route::apiResource('question-banks', QuestionBankController::class)
            ->only(['index', 'store', 'show'])
            ->parameters(['question-banks' => 'questionBank']);
        Route::get('question-banks/{questionBank:uuid}/questions', [QuestionBankController::class, 'listQuestions'])
            ->name('question-banks.questions.index');
        Route::post('question-banks/{questionBank:uuid}/questions', [QuestionBankController::class, 'addQuestion'])
            ->name('question-banks.questions.store');
        Route::patch('questions/{question:uuid}', [QuestionBankController::class, 'updateQuestion'])
            ->name('questions.update');
        Route::delete('questions/{question:uuid}', [QuestionBankController::class, 'deleteQuestion'])
            ->name('questions.destroy');

        // Quizzes CRUD
        Route::apiResource('quizzes', QuizController::class)
            ->parameters(['quizzes' => 'quiz']);
        Route::post('quizzes/{quiz:uuid}/questions/sync', [QuizController::class, 'syncQuestions'])
            ->name('quizzes.questions.sync');
        Route::post('quizzes/{quiz:uuid}/attach-questions', [QuizController::class, 'attachQuestions'])
            ->name('quizzes.questions.attach');
        Route::post('quizzes/{quiz:uuid}/attach-random', [QuizController::class, 'attachRandom'])
            ->name('quizzes.questions.attach-random');
        Route::post('quizzes/{quiz:uuid}/detach-questions', [QuizController::class, 'detachQuestions'])
            ->name('quizzes.questions.detach');
        Route::post('quizzes/{quiz:uuid}/reorder-questions', [QuizController::class, 'reorderQuestions'])
            ->name('quizzes.questions.reorder');
        Route::post('quizzes/{quiz:uuid}/duplicate', [QuizController::class, 'duplicate'])
            ->name('quizzes.duplicate');
        Route::post('quizzes/{quiz:uuid}/publish', [QuizController::class, 'publish'])
            ->name('quizzes.publish');
    });

    /* ============================================================
     * LIVE SESSION HOSTING — Trainer + Facilitator + Admin (SRS 3.2 facilitator helps)
     * ============================================================ */
    Route::middleware(['auth:sanctum', 'active.user', 'role:trainer|facilitator|system_admin'])->group(function () {
        Route::post('quizzes/{quiz:uuid}/host', [LiveQuizController::class, 'hostStart'])->name('quizzes.host.start');

        Route::prefix('sessions/{session:uuid}')->group(function () {
            Route::post('start-question', [LiveQuizController::class, 'hostStartQuestion'])->name('sessions.question.start');
            Route::post('end-question', [LiveQuizController::class, 'hostEndQuestion'])->name('sessions.question.end');
            Route::post('complete', [LiveQuizController::class, 'hostComplete'])->name('sessions.complete');
            Route::get('leaderboard', [LiveQuizController::class, 'leaderboard'])->name('sessions.leaderboard');
            Route::get('participants', [LiveQuizController::class, 'participants'])->name('sessions.participants');
            Route::get('answer-count', [LiveQuizController::class, 'answerCount'])->name('sessions.answer-count');
        });
    });

    /* ============================================================
     * ADMIN-ONLY: system-wide operations (SRS 3.1)
     * Manage users · Approve courses · System stats · Audit log
     * ============================================================ */
    Route::middleware(['auth:sanctum', 'active.user', 'role:system_admin'])->prefix('admin')->group(function () {
        Route::get('audit-log', [\App\Http\Controllers\Api\V1\Admin\AuditLogController::class, 'index'])->name('admin.audit-log');
        Route::get('stats', [\App\Http\Controllers\Api\V1\Admin\StatsController::class, 'index'])->name('admin.stats');

        // Course approvals (SRS 3.1)
        Route::get('course-approvals', [\App\Http\Controllers\Api\V1\Admin\CourseApprovalController::class, 'pending'])->name('admin.course-approvals');
        Route::post('courses/{course:uuid}/approve', [\App\Http\Controllers\Api\V1\Admin\CourseApprovalController::class, 'approve'])->name('admin.courses.approve');
        Route::post('courses/{course:uuid}/reject', [\App\Http\Controllers\Api\V1\Admin\CourseApprovalController::class, 'reject'])->name('admin.courses.reject');
    });

    /* ============================================================
     * COURSE MANAGEMENT (SRS 4.2 Module 2)
     * ============================================================ */
    // Public list + detail (any authenticated user; visibility filtered inside controller)
    Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
        Route::get('courses', [\App\Http\Controllers\Api\V1\Course\CourseController::class, 'index'])->name('courses.index');
        Route::get('courses/{course:uuid}', [\App\Http\Controllers\Api\V1\Course\CourseController::class, 'show'])->name('courses.show');
    });

    // Trainer/Admin — create + edit + submit + upload thumbnail + delete
    Route::middleware(['auth:sanctum', 'active.user', 'role:trainer|system_admin'])->group(function () {
        Route::get('instructors', [\App\Http\Controllers\Api\V1\Course\CourseController::class, 'instructors'])->name('instructors.index');

        Route::post('courses', [\App\Http\Controllers\Api\V1\Course\CourseController::class, 'store'])->name('courses.store');
        Route::patch('courses/{course:uuid}', [\App\Http\Controllers\Api\V1\Course\CourseController::class, 'update'])->name('courses.update');
        Route::delete('courses/{course:uuid}', [\App\Http\Controllers\Api\V1\Course\CourseController::class, 'destroy'])->name('courses.destroy');
        Route::post('courses/{course:uuid}/submit', [\App\Http\Controllers\Api\V1\Course\CourseController::class, 'submit'])->name('courses.submit');
        Route::post('courses/{course:uuid}/thumbnail', [\App\Http\Controllers\Api\V1\Course\CourseController::class, 'uploadThumbnail'])->name('courses.thumbnail');
        Route::post('courses/{course:uuid}/reorder-modules', [\App\Http\Controllers\Api\V1\Course\ModuleLessonController::class, 'reorderModules'])->name('courses.reorder-modules');

        // Modules
        Route::post('courses/{course:uuid}/modules', [\App\Http\Controllers\Api\V1\Course\ModuleLessonController::class, 'storeModule'])->name('modules.store');
        Route::patch('modules/{module:uuid}', [\App\Http\Controllers\Api\V1\Course\ModuleLessonController::class, 'updateModule'])->name('modules.update');
        Route::delete('modules/{module:uuid}', [\App\Http\Controllers\Api\V1\Course\ModuleLessonController::class, 'destroyModule'])->name('modules.destroy');
        Route::post('modules/{module:uuid}/attach-quiz', [\App\Http\Controllers\Api\V1\Course\ModuleLessonController::class, 'attachQuiz'])->name('modules.attach-quiz');
        Route::post('modules/{module:uuid}/reorder-lessons', [\App\Http\Controllers\Api\V1\Course\ModuleLessonController::class, 'reorderLessons'])->name('modules.reorder-lessons');
        Route::post('quizzes/{quiz:uuid}/detach', [\App\Http\Controllers\Api\V1\Course\ModuleLessonController::class, 'detachQuiz'])->name('quizzes.detach');

        // Lessons
        Route::post('modules/{module:uuid}/lessons', [\App\Http\Controllers\Api\V1\Course\ModuleLessonController::class, 'storeLesson'])->name('lessons.store');
        Route::patch('lessons/{lesson:uuid}', [\App\Http\Controllers\Api\V1\Course\ModuleLessonController::class, 'updateLesson'])->name('lessons.update');
        Route::delete('lessons/{lesson:uuid}', [\App\Http\Controllers\Api\V1\Course\ModuleLessonController::class, 'destroyLesson'])->name('lessons.destroy');

        // Materials (SRS Module 3 — Learning Materials) — async processing pipeline
        Route::post('lessons/{lesson:uuid}/materials', [\App\Http\Controllers\Api\V1\Course\MaterialController::class, 'store'])->name('materials.store');
        Route::patch('materials/{material:uuid}', [\App\Http\Controllers\Api\V1\Course\MaterialController::class, 'update'])->name('materials.update');
        Route::delete('materials/{material:uuid}', [\App\Http\Controllers\Api\V1\Course\MaterialController::class, 'destroy'])->name('materials.destroy');
        Route::post('lessons/{lesson:uuid}/materials/reorder', [\App\Http\Controllers\Api\V1\Course\MaterialController::class, 'reorder'])->name('materials.reorder');
    });

    // Material status + streaming — auth required, visibility filtered by enrollment (or trainer/admin)
    Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
        Route::get('materials/{material:uuid}/status', [\App\Http\Controllers\Api\V1\Course\MaterialController::class, 'status'])->name('materials.status');
        Route::get('materials/{material:uuid}/stream', [\App\Http\Controllers\Api\V1\Course\MaterialController::class, 'stream'])
            ->withoutMiddleware(['throttle:api'])  // ranged streams pull many chunks
            ->name('materials.stream');
    });

    Route::middleware(['auth:sanctum', 'active.user', 'role:trainer|system_admin'])->group(function () {
        // Placeholder to keep route grouping consistent after the extract above

        // Assignments (SRS "Lesson Contains Assignments" + Module 9)
        Route::post('lessons/{lesson:uuid}/assignments', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'store'])->name('assignments.store');
        Route::patch('assignments/{assignment:uuid}', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'update'])->name('assignments.update');
        Route::delete('assignments/{assignment:uuid}', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'destroy'])->name('assignments.destroy');
        Route::post('assignments/{assignment:uuid}/brief', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'uploadBrief'])->name('assignments.brief.upload');
        Route::delete('assignments/{assignment:uuid}/brief', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'deleteBrief'])->name('assignments.brief.delete');
        Route::post('submissions/{submission:uuid}/grade', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'grade'])->name('submissions.grade');
    });

    // Assignment view + submission listing — trainer OR student (visibility handled in controller)
    Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
        Route::get('assignments/{assignment:uuid}', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'show'])->name('assignments.show');
        Route::get('assignments/{assignment:uuid}/submissions', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'submissions'])->name('assignments.submissions');
    });

    // Signed download endpoints for private brief + submission files (SRS Module 9 — no /storage/ direct access)
    // Authenticated + signed: signature verifies short-lived URL, controller enforces per-user access policy.
    Route::middleware(['auth:sanctum', 'active.user', 'signed'])->group(function () {
        Route::get('download/assignment-brief/{assignment:uuid}', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'downloadBrief'])
            ->name('assignments.brief.download');
        Route::get('download/submission/{submission:uuid}', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'downloadSubmission'])
            ->name('submissions.file.download');
    });
    Route::middleware(['auth:sanctum', 'active.user', 'role:student|corporate_client'])->group(function () {
        Route::post('assignments/{assignment:uuid}/submit', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'submit'])->name('assignments.submit');
    });

    // Student — enroll + track progress
    Route::middleware(['auth:sanctum', 'active.user', 'role:student|corporate_client'])->group(function () {
        Route::post('courses/{course:uuid}/enroll', [\App\Http\Controllers\Api\V1\Course\EnrollmentController::class, 'enroll'])->name('courses.enroll');
        Route::post('lessons/{lesson:uuid}/mark-complete', [\App\Http\Controllers\Api\V1\Course\EnrollmentController::class, 'markLessonComplete'])->name('lessons.mark-complete');
    });
    Route::middleware(['auth:sanctum', 'active.user', 'role:student'])->prefix('student')->group(function () {
        Route::get('my-enrollments', [\App\Http\Controllers\Api\V1\Course\EnrollmentController::class, 'myEnrollments'])->name('student.my-enrollments');
    });

    /* ============================================================
     * ATTENDANCE MANAGEMENT (SRS Module 4)
     * ============================================================ */
    // Trainer manages sessions
    Route::middleware(['auth:sanctum', 'active.user', 'role:trainer|facilitator|system_admin'])->group(function () {
        Route::get('attendance-sessions', [\App\Http\Controllers\Api\V1\Attendance\AttendanceSessionController::class, 'index'])->name('attendance.sessions.index');
        Route::post('attendance-sessions', [\App\Http\Controllers\Api\V1\Attendance\AttendanceSessionController::class, 'store'])->name('attendance.sessions.store');
        Route::get('attendance-sessions/{session:uuid}', [\App\Http\Controllers\Api\V1\Attendance\AttendanceSessionController::class, 'show'])->name('attendance.sessions.show');
        Route::delete('attendance-sessions/{session:uuid}', [\App\Http\Controllers\Api\V1\Attendance\AttendanceSessionController::class, 'destroy'])->name('attendance.sessions.destroy');
        Route::post('attendance-sessions/{session:uuid}/open', [\App\Http\Controllers\Api\V1\Attendance\AttendanceSessionController::class, 'open'])->name('attendance.sessions.open');
        Route::post('attendance-sessions/{session:uuid}/close', [\App\Http\Controllers\Api\V1\Attendance\AttendanceSessionController::class, 'close'])->name('attendance.sessions.close');
        Route::post('attendance-sessions/{session:uuid}/rotate-qr', [\App\Http\Controllers\Api\V1\Attendance\AttendanceSessionController::class, 'rotateQr'])->name('attendance.sessions.rotate-qr');
        Route::get('attendance-sessions/{session:uuid}/qr', [\App\Http\Controllers\Api\V1\Attendance\AttendanceSessionController::class, 'qr'])->name('attendance.sessions.qr');
        Route::post('attendance-sessions/{session:uuid}/mark', [\App\Http\Controllers\Api\V1\Attendance\AttendanceRecordController::class, 'mark'])->name('attendance.records.mark');

        // Reports
        Route::get('attendance-sessions/{session:uuid}/report', [\App\Http\Controllers\Api\V1\Attendance\AttendanceReportController::class, 'session'])->name('attendance.reports.session');
        Route::get('courses/{course:uuid}/attendance-summary', [\App\Http\Controllers\Api\V1\Attendance\AttendanceReportController::class, 'courseSummary'])->name('attendance.reports.course');
    });

    // Student self check-in via QR
    Route::middleware(['auth:sanctum', 'active.user', 'role:student|corporate_client'])->group(function () {
        Route::post('attendance/check-in', [\App\Http\Controllers\Api\V1\Attendance\AttendanceRecordController::class, 'checkIn'])->name('attendance.check-in');
    });

    /* ============================================================
     * TRAINER-ONLY: my resources (SRS 3.2)
     * ============================================================ */
    Route::middleware(['auth:sanctum', 'active.user', 'role:trainer|facilitator|system_admin'])->prefix('trainer')->group(function () {
        Route::get('my-quizzes', [\App\Http\Controllers\Api\V1\Trainer\MyQuizzesController::class, 'index'])->name('trainer.my-quizzes');
        Route::get('my-sessions', [\App\Http\Controllers\Api\V1\Trainer\MyQuizzesController::class, 'sessions'])->name('trainer.my-sessions');
        Route::get('dashboard', [\App\Http\Controllers\Api\V1\Dashboard\TrainerDashboardController::class, 'index'])
            ->middleware(['throttle:60,1', 'role:trainer|system_admin'])->name('trainer.dashboard');
        Route::get('courses/{courseUuid}/report', [\App\Http\Controllers\Api\V1\Dashboard\TrainerDashboardController::class, 'courseReport'])
            ->where('courseUuid', '[0-9a-f-]{36}')
            ->middleware(['throttle:30,1', 'role:trainer|system_admin'])->name('trainer.course.report');
    });

    /* ============================================================
     * STUDENT-ONLY: my learning (SRS 3.3)
     * ============================================================ */
    Route::middleware(['auth:sanctum', 'active.user', 'role:student'])->prefix('student')->group(function () {
        Route::get('my-attempts', [\App\Http\Controllers\Api\V1\Quiz\AttemptController::class, 'myAttempts'])->name('student.attempts');
        Route::get('available-quizzes', [\App\Http\Controllers\Api\V1\Student\MyLearningController::class, 'available'])->name('student.available');
        Route::get('exams', [\App\Http\Controllers\Api\V1\Quiz\AttemptController::class, 'availableExams'])->name('student.exams');
        Route::get('assignments', [\App\Http\Controllers\Api\V1\Course\AssignmentController::class, 'studentIndex'])->name('student.assignments');
        Route::get('certificates', [\App\Http\Controllers\Api\V1\Certificate\CertificateController::class, 'index'])->name('student.certificates');
        Route::get('dashboard', [\App\Http\Controllers\Api\V1\Dashboard\StudentDashboardController::class, 'index'])
            ->middleware('throttle:60,1')->name('student.dashboard');
    });

    // Certificate detail + PDF + QR — student sees own; trainer/admin allowed
    // PDF is expensive (DomPDF renders ~1MB) → rate-limited separately from JSON GETs.
    Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
        Route::get('certificates/{certificate:uuid}', [\App\Http\Controllers\Api\V1\Certificate\CertificateController::class, 'show'])->name('certificates.show');
        Route::get('certificates/{certificate:uuid}/pdf', [\App\Http\Controllers\Api\V1\Certificate\CertificateController::class, 'pdf'])
            ->middleware('throttle:20,1')->name('certificates.pdf');
        Route::get('certificates/{certificate:uuid}/qr', [\App\Http\Controllers\Api\V1\Certificate\CertificateController::class, 'qr'])
            ->middleware('throttle:60,1')->name('certificates.qr');
    });

    // Admin — list all + revoke
    Route::middleware(['auth:sanctum', 'active.user', 'role:system_admin'])->prefix('admin')->group(function () {
        Route::get('certificates', [\App\Http\Controllers\Api\V1\Certificate\AdminCertificateController::class, 'index'])->name('admin.certificates');
        Route::post('certificates/{certificate:uuid}/revoke', [\App\Http\Controllers\Api\V1\Certificate\AdminCertificateController::class, 'revoke'])->name('admin.certificates.revoke');

        // SRS Module 15 — Admin broadcast announcements
        Route::get('announcements', [\App\Http\Controllers\Api\V1\Notifications\BroadcastController::class, 'index'])->name('admin.announcements.index');
        Route::post('announcements/preview', [\App\Http\Controllers\Api\V1\Notifications\BroadcastController::class, 'preview'])->name('admin.announcements.preview');
        Route::post('announcements', [\App\Http\Controllers\Api\V1\Notifications\BroadcastController::class, 'store'])
            ->middleware('throttle:10,60')->name('admin.announcements.store');
    });

    /* ============================================================
     * QUIZ ATTEMPTS (SRS Module 8 — Examination System)
     * Any authenticated user (students take, trainers can view own)
     * ============================================================ */
    Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
        Route::post('quizzes/{quiz:uuid}/attempts', [\App\Http\Controllers\Api\V1\Quiz\AttemptController::class, 'start'])->name('attempts.start');
        Route::get('attempts/{attempt:uuid}', [\App\Http\Controllers\Api\V1\Quiz\AttemptController::class, 'show'])->name('attempts.show');
        Route::post('attempts/{attempt:uuid}/answer', [\App\Http\Controllers\Api\V1\Quiz\AttemptController::class, 'answer'])->name('attempts.answer');
        Route::post('attempts/{attempt:uuid}/complete', [\App\Http\Controllers\Api\V1\Quiz\AttemptController::class, 'complete'])->name('attempts.complete');
        Route::post('attempts/{attempt:uuid}/violation', [\App\Http\Controllers\Api\V1\Quiz\AttemptController::class, 'violation'])->name('attempts.violation');
    });

    /* ============================================================
     * CORPORATE CLIENT: my employees (SRS 3.4)
     * ============================================================ */
    Route::middleware(['auth:sanctum', 'active.user', 'role:corporate_client'])->prefix('corporate')->group(function () {
        Route::get('my-employees', [\App\Http\Controllers\Api\V1\Corporate\EmployeesController::class, 'index'])->name('corporate.employees');
        Route::post('invite', [\App\Http\Controllers\Api\V1\Corporate\EmployeesController::class, 'invite'])->name('corporate.invite');
        Route::get('dashboard', [\App\Http\Controllers\Api\V1\Dashboard\CorporateDashboardController::class, 'index'])
            ->middleware('throttle:60,1')->name('corporate.dashboard');
        Route::get('employees/{employeeUuid}/report', [\App\Http\Controllers\Api\V1\Dashboard\CorporateDashboardController::class, 'employeeReport'])
            ->where('employeeUuid', '[0-9a-f-]{36}')
            ->middleware('throttle:30,1')->name('corporate.employee.report');
    });

    /* ============================================================
     * PAYMENTS (SRS Module 12)
     *   - Public webhook (signature-verified, high throttle)
     *   - Authenticated invoice + payment endpoints
     * ============================================================ */

    // PUBLIC webhook — providers POST here. High throttle to handle retries.
    Route::post('payments/webhook/{provider}', [\App\Http\Controllers\Api\V1\Payment\WebhookController::class, 'handle'])
        ->where('provider', '[a-z_]+')
        ->middleware('throttle:120,1')
        ->name('payments.webhook');

    // SRS Module 13 — Public trainer directory (no auth required)
    Route::get('trainers', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerDirectoryController::class, 'index'])
        ->middleware('throttle:60,1')->name('trainers.directory');
    Route::get('trainers/{trainer:public_slug}', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerDirectoryController::class, 'show'])
        ->where('trainer', '[a-z0-9-]+')
        ->middleware('throttle:60,1')->name('trainers.show');

    // SRS Module 14 — Public forum browse (no auth required for read)
    Route::prefix('forum')->group(function () {
        Route::get('categories', [\App\Http\Controllers\Api\V1\Forum\ForumController::class, 'categories'])
            ->middleware('throttle:60,1')->name('forum.categories');
        Route::get('threads', [\App\Http\Controllers\Api\V1\Forum\ForumController::class, 'threads'])
            ->middleware('throttle:120,1')->name('forum.threads.index');
        Route::get('threads/{thread:uuid}', [\App\Http\Controllers\Api\V1\Forum\ForumController::class, 'show'])
            ->where('thread', '[0-9a-f-]{36}')
            ->middleware('throttle:120,1')->name('forum.threads.show');
    });

    // Signed proof-file download (link comes from authenticated proofUrl call).
    // Auth required so the audience claim in the signature can be verified against the caller.
    Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
        Route::get('trainer/portal/proof/{type}/{uuid}/download', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerPortalController::class, 'proofDownload'])
            ->where('type', 'qualifications|certifications')
            ->where('uuid', '[0-9a-f-]{36}')
            ->name('trainer.proof.download');

        // SRS Module 14 — forum attachment signed download (audience-bound)
        Route::get('forum/attachments/{attachment:uuid}/download', [\App\Http\Controllers\Api\V1\Forum\AttachmentController::class, 'download'])
            ->where('attachment', '[0-9a-f-]{36}')
            ->name('forum.attachments.download');
    });

    Route::middleware(['auth:sanctum', 'active.user'])->group(function () {
        Route::get('payments/providers', [\App\Http\Controllers\Api\V1\Payment\PaymentController::class, 'providers'])
            ->name('payments.providers');

        // Invoices
        Route::get('invoices', [\App\Http\Controllers\Api\V1\Payment\InvoiceController::class, 'index'])
            ->middleware('throttle:60,1')->name('invoices.index');
        Route::post('invoices', [\App\Http\Controllers\Api\V1\Payment\InvoiceController::class, 'storeForCourse'])
            ->middleware('throttle:20,1')->name('invoices.store');
        Route::get('invoices/{invoice:uuid}', [\App\Http\Controllers\Api\V1\Payment\InvoiceController::class, 'show'])
            ->where('invoice', '[0-9a-f-]{36}')->name('invoices.show');
        Route::get('invoices/{invoice:uuid}/pdf', [\App\Http\Controllers\Api\V1\Payment\InvoiceController::class, 'pdf'])
            ->where('invoice', '[0-9a-f-]{36}')
            ->middleware('throttle:20,1')->name('invoices.pdf');
        Route::get('invoices/{invoice:uuid}/receipt', [\App\Http\Controllers\Api\V1\Payment\InvoiceController::class, 'receipt'])
            ->where('invoice', '[0-9a-f-]{36}')
            ->middleware('throttle:20,1')->name('invoices.receipt');

        // Initiate payment against an invoice
        Route::post('invoices/{invoice:uuid}/pay', [\App\Http\Controllers\Api\V1\Payment\PaymentController::class, 'initiate'])
            ->where('invoice', '[0-9a-f-]{36}')
            ->middleware('throttle:10,1')->name('payments.initiate');

        // Payment status poll (used while STK push is pending)
        Route::get('payments/{payment:uuid}', [\App\Http\Controllers\Api\V1\Payment\PaymentController::class, 'show'])
            ->where('payment', '[0-9a-f-]{36}')
            ->middleware('throttle:120,1')->name('payments.show');

        // SRS Module 13 — Trainer portal (self-management)
        Route::middleware('role:trainer|system_admin')->prefix('trainer/portal')->group(function () {
            Route::get('profile', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerPortalController::class, 'myProfile'])->name('trainer.portal.profile');
            Route::patch('profile', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerPortalController::class, 'updateProfile'])->name('trainer.portal.profile.update');

            Route::post('qualifications', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerPortalController::class, 'storeQualification'])
                ->middleware('throttle:20,1')->name('trainer.qualifications.store');
            Route::delete('qualifications/{qualification:uuid}', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerPortalController::class, 'deleteQualification'])
                ->where('qualification', '[0-9a-f-]{36}')->name('trainer.qualifications.delete');

            Route::post('certifications', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerPortalController::class, 'storeCertification'])
                ->middleware('throttle:20,1')->name('trainer.certifications.store');
            Route::delete('certifications/{certification:uuid}', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerPortalController::class, 'deleteCertification'])
                ->where('certification', '[0-9a-f-]{36}')->name('trainer.certifications.delete');

            Route::post('experiences', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerPortalController::class, 'storeExperience'])
                ->middleware('throttle:30,1')->name('trainer.experiences.store');
            Route::delete('experiences/{experience:uuid}', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerPortalController::class, 'deleteExperience'])
                ->where('experience', '[0-9a-f-]{36}')->name('trainer.experiences.delete');

            Route::get('reviews', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerReviewController::class, 'myReviews'])->name('trainer.reviews.mine');

            Route::get('proof/{type}/{uuid}/url', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerPortalController::class, 'proofUrl'])
                ->where('type', 'qualifications|certifications')
                ->where('uuid', '[0-9a-f-]{36}')
                ->middleware('throttle:20,1')->name('trainer.proof.url');
        });

        // Student posts a review — auth required
        Route::post('trainers/{trainer:public_slug}/reviews', [\App\Http\Controllers\Api\V1\TrainerPortal\TrainerReviewController::class, 'store'])
            ->middleware(['role:student', 'throttle:10,1'])
            ->where('trainer', '[a-z0-9-]+')
            ->name('trainers.reviews.store');

        // Admin verification console
        Route::middleware('role:system_admin')->prefix('admin')->group(function () {
            Route::get('trainer-verifications', [\App\Http\Controllers\Api\V1\TrainerPortal\AdminVerificationController::class, 'pending'])->name('admin.trainer.verifications');
            Route::post('trainer-qualifications/{qualification:uuid}/verify', [\App\Http\Controllers\Api\V1\TrainerPortal\AdminVerificationController::class, 'verifyQualification'])
                ->where('qualification', '[0-9a-f-]{36}')->name('admin.qualification.verify');
            Route::post('trainer-qualifications/{qualification:uuid}/reject', [\App\Http\Controllers\Api\V1\TrainerPortal\AdminVerificationController::class, 'rejectQualification'])
                ->where('qualification', '[0-9a-f-]{36}')->name('admin.qualification.reject');
            Route::post('trainer-certifications/{certification:uuid}/verify', [\App\Http\Controllers\Api\V1\TrainerPortal\AdminVerificationController::class, 'verifyCertification'])
                ->where('certification', '[0-9a-f-]{36}')->name('admin.certification.verify');
            Route::post('trainer-certifications/{certification:uuid}/reject', [\App\Http\Controllers\Api\V1\TrainerPortal\AdminVerificationController::class, 'rejectCertification'])
                ->where('certification', '[0-9a-f-]{36}')->name('admin.certification.reject');
            Route::post('trainer-reviews/{review:uuid}/hide', [\App\Http\Controllers\Api\V1\TrainerPortal\AdminVerificationController::class, 'hideReview'])
                ->where('review', '[0-9a-f-]{36}')->name('admin.trainer.review.hide');
        });

        // Admin: void an issued invoice + issue refunds
        Route::middleware('role:system_admin')->group(function () {
            Route::post('invoices/{invoice:uuid}/void', [\App\Http\Controllers\Api\V1\Payment\InvoiceController::class, 'void'])
                ->where('invoice', '[0-9a-f-]{36}')->name('invoices.void');
            Route::post('payments/{payment:uuid}/refund', [\App\Http\Controllers\Api\V1\Payment\RefundController::class, 'store'])
                ->where('payment', '[0-9a-f-]{36}')->name('payments.refund');
        });

        // SRS Module 14 — Discussion Forum (authenticated writes)
        Route::prefix('forum')->group(function () {
            // Thread CRUD + moderation + voting
            Route::post('threads', [\App\Http\Controllers\Api\V1\Forum\ThreadController::class, 'store'])
                ->middleware('throttle:30,60')->name('forum.threads.store');
            Route::patch('threads/{thread:uuid}', [\App\Http\Controllers\Api\V1\Forum\ThreadController::class, 'update'])
                ->where('thread', '[0-9a-f-]{36}')->name('forum.threads.update');
            Route::delete('threads/{thread:uuid}', [\App\Http\Controllers\Api\V1\Forum\ThreadController::class, 'destroy'])
                ->where('thread', '[0-9a-f-]{36}')->name('forum.threads.destroy');
            Route::patch('threads/{thread:uuid}/moderate', [\App\Http\Controllers\Api\V1\Forum\ThreadController::class, 'moderate'])
                ->where('thread', '[0-9a-f-]{36}')->name('forum.threads.moderate');
            Route::post('threads/{thread:uuid}/vote', [\App\Http\Controllers\Api\V1\Forum\ThreadController::class, 'vote'])
                ->where('thread', '[0-9a-f-]{36}')->middleware('throttle:60,1')->name('forum.threads.vote');
            Route::post('threads/{thread:uuid}/accept/{post:uuid}', [\App\Http\Controllers\Api\V1\Forum\ThreadController::class, 'acceptAnswer'])
                ->where('thread', '[0-9a-f-]{36}')->where('post', '[0-9a-f-]{36}')
                ->name('forum.threads.accept');
            Route::post('threads/{thread:uuid}/subscribe', [\App\Http\Controllers\Api\V1\Forum\ThreadController::class, 'subscribe'])
                ->where('thread', '[0-9a-f-]{36}')->name('forum.threads.subscribe');
            Route::delete('threads/{thread:uuid}/subscribe', [\App\Http\Controllers\Api\V1\Forum\ThreadController::class, 'unsubscribe'])
                ->where('thread', '[0-9a-f-]{36}')->name('forum.threads.unsubscribe');

            // Posts (replies)
            Route::post('threads/{thread:uuid}/posts', [\App\Http\Controllers\Api\V1\Forum\PostController::class, 'store'])
                ->where('thread', '[0-9a-f-]{36}')->name('forum.posts.store');
            Route::patch('posts/{post:uuid}', [\App\Http\Controllers\Api\V1\Forum\PostController::class, 'update'])
                ->where('post', '[0-9a-f-]{36}')->name('forum.posts.update');
            Route::delete('posts/{post:uuid}', [\App\Http\Controllers\Api\V1\Forum\PostController::class, 'destroy'])
                ->where('post', '[0-9a-f-]{36}')->name('forum.posts.destroy');
            Route::patch('posts/{post:uuid}/moderate', [\App\Http\Controllers\Api\V1\Forum\PostController::class, 'moderate'])
                ->where('post', '[0-9a-f-]{36}')->name('forum.posts.moderate');
            Route::post('posts/{post:uuid}/vote', [\App\Http\Controllers\Api\V1\Forum\PostController::class, 'vote'])
                ->where('post', '[0-9a-f-]{36}')->middleware('throttle:60,1')->name('forum.posts.vote');

            // Reports
            Route::post('reports', [\App\Http\Controllers\Api\V1\Forum\ReportController::class, 'store'])
                ->middleware('throttle:20,60')->name('forum.reports.store');
            Route::get('reports', [\App\Http\Controllers\Api\V1\Forum\ReportController::class, 'index'])
                ->name('forum.reports.index');
            Route::patch('reports/{report:uuid}', [\App\Http\Controllers\Api\V1\Forum\ReportController::class, 'update'])
                ->where('report', '[0-9a-f-]{36}')->name('forum.reports.update');

            // Notifications for the logged-in user
            Route::get('notifications', [\App\Http\Controllers\Api\V1\Forum\NotificationController::class, 'index'])
                ->name('forum.notifications.index');
            Route::post('notifications/{id}/read', [\App\Http\Controllers\Api\V1\Forum\NotificationController::class, 'markRead'])
                ->name('forum.notifications.read');
            Route::post('notifications/read-all', [\App\Http\Controllers\Api\V1\Forum\NotificationController::class, 'markAllRead'])
                ->name('forum.notifications.read-all');

            // Attachments (upload/signed-url/delete). Download is registered outside
            // this auth group so signed URLs verify audience on their own path.
            Route::post('threads/{thread:uuid}/attachments', [\App\Http\Controllers\Api\V1\Forum\AttachmentController::class, 'storeForThread'])
                ->where('thread', '[0-9a-f-]{36}')->middleware('throttle:20,60')
                ->name('forum.attachments.thread.store');
            Route::post('posts/{post:uuid}/attachments', [\App\Http\Controllers\Api\V1\Forum\AttachmentController::class, 'storeForPost'])
                ->where('post', '[0-9a-f-]{36}')->middleware('throttle:20,60')
                ->name('forum.attachments.post.store');
            Route::get('attachments/{attachment:uuid}/url', [\App\Http\Controllers\Api\V1\Forum\AttachmentController::class, 'url'])
                ->where('attachment', '[0-9a-f-]{36}')
                ->name('forum.attachments.url');
            Route::delete('attachments/{attachment:uuid}', [\App\Http\Controllers\Api\V1\Forum\AttachmentController::class, 'destroy'])
                ->where('attachment', '[0-9a-f-]{36}')
                ->name('forum.attachments.destroy');
        });

        // SRS Module 15 — Notification preferences + unified inbox + device tokens
        Route::prefix('notifications')->group(function () {
            Route::get('preferences', [\App\Http\Controllers\Api\V1\Notifications\PreferencesController::class, 'index'])
                ->name('notifications.prefs.index');
            Route::put('preferences', [\App\Http\Controllers\Api\V1\Notifications\PreferencesController::class, 'update'])
                ->middleware('throttle:20,60')->name('notifications.prefs.update');

            Route::get('inbox', [\App\Http\Controllers\Api\V1\Notifications\InboxController::class, 'index'])
                ->name('notifications.inbox.index');
            Route::post('inbox/{id}/read', [\App\Http\Controllers\Api\V1\Notifications\InboxController::class, 'markRead'])
                ->name('notifications.inbox.read');
            Route::post('inbox/read-all', [\App\Http\Controllers\Api\V1\Notifications\InboxController::class, 'markAllRead'])
                ->name('notifications.inbox.read-all');
            Route::delete('inbox/{id}', [\App\Http\Controllers\Api\V1\Notifications\InboxController::class, 'destroy'])
                ->name('notifications.inbox.destroy');
        });

        // Mobile push device registration (M15 infra, activated when mobile ships)
        Route::post('devices', [\App\Http\Controllers\Api\V1\Notifications\DeviceTokenController::class, 'store'])
            ->name('devices.store');
        Route::delete('devices', [\App\Http\Controllers\Api\V1\Notifications\DeviceTokenController::class, 'destroy'])
            ->name('devices.destroy');
    });
});
