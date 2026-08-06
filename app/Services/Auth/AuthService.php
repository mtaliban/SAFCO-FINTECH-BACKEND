<?php

namespace App\Services\Auth;

use App\Events\User\AccountLocked;
use App\Events\User\LoginFailed;
use App\Events\User\UserLoggedIn;
use App\Events\User\UserLoggedOut;
use App\Events\User\UserRegistered;
use App\Models\LoginHistory;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\EventBus\EventDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Jenssegers\Agent\Agent;

class AuthService
{
    protected const MAX_FAILED_ATTEMPTS = 5;
    protected const LOCK_MINUTES = 30;

    public function __construct(protected EventDispatcher $events)
    {
    }

    /**
     * Register a new user with email + password.
     * Also creates the associated profile inside a single DB transaction,
     * then persists a UserRegistered event to the outbox atomically.
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'organization_id' => $data['organization_id'] ?? null,
                'status' => 'pending',
                'auth_provider' => 'email',
                'locale' => $data['locale'] ?? 'en',
                'timezone' => $data['timezone'] ?? 'Africa/Dar_es_Salaam',
            ]);

            UserProfile::create([
                'user_id' => $user->id,
                'full_name' => $data['full_name'],
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'gender' => $data['gender'] ?? null,
                'position' => $data['position'] ?? null,
                'country' => $data['country'] ?? 'Tanzania',
            ]);

            $roleName = $data['role'] ?? 'student';
            $user->assignRole($roleName);

            $this->events->dispatch(
                new UserRegistered($user, $data['registration_channel'] ?? 'email'),
                aggregateType: User::class,
                aggregateId: $user->id
            );

            return $user->fresh(['profile', 'organization']);
        });
    }

    /**
     * Attempt to authenticate a user with email/phone + password.
     * On success: creates a Sanctum token and dispatches UserLoggedIn.
     * On failure: increments counter, dispatches LoginFailed, and
     * locks the account after MAX_FAILED_ATTEMPTS.
     */
    public function login(array $credentials, Request $request): array
    {
        $identifier = $credentials['identifier']; // email or phone
        $password = $credentials['password'];
        $deviceName = $credentials['device_name'] ?? 'web';

        $user = User::where('email', $identifier)
                    ->orWhere('phone', $identifier)
                    ->first();

        if (! $user || ! Hash::check($password, $user->password ?? '')) {
            $this->recordFailedLogin($identifier, 'invalid_credentials', $request);
            $this->events->dispatch(new LoginFailed(
                identifier: $identifier,
                reason: 'invalid_credentials',
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            ));

            if ($user) {
                $this->handleFailedAttempt($user);
            }

            throw ValidationException::withMessages([
                'identifier' => __('auth.failed'),
            ]);
        }

        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'identifier' => __('Your account is locked. Try again after :time', [
                    'time' => $user->locked_until->diffForHumans(),
                ]),
            ]);
        }

        if (! $user->isActive() && $user->status !== 'pending') {
            throw ValidationException::withMessages([
                'identifier' => __('Your account is :status.', ['status' => $user->status]),
            ]);
        }

        // Success — issue token, reset counters, record history, emit event
        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $this->recordSuccessfulLogin($user, $request);

        $agent = new Agent();
        $agent->setUserAgent($request->userAgent() ?? '');

        $this->events->dispatch(
            new UserLoggedIn(
                user: $user,
                authMethod: 'email',
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
                deviceType: $agent->deviceType(),
            ),
            aggregateType: User::class,
            aggregateId: $user->id
        );

        $abilities = $user->getPermissionNames()->toArray();
        $token = $user->createToken(
            $deviceName,
            $abilities ?: ['*'],
            now()->addMinutes((int) config('sanctum.expiration', 1440))
        );

        return [
            'user' => $user->fresh(['profile', 'organization', 'roles']),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'requires_2fa' => $user->two_factor_enabled,
        ];
    }

    public function logout(User $user, Request $request): void
    {
        $user->currentAccessToken()?->delete();

        $this->events->dispatch(
            new UserLoggedOut($user, $request->ip()),
            aggregateType: User::class,
            aggregateId: $user->id
        );

        LoginHistory::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'status' => 'logged_out',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    protected function handleFailedAttempt(User $user): void
    {
        $user->increment('failed_login_attempts');

        if ($user->failed_login_attempts >= self::MAX_FAILED_ATTEMPTS) {
            $user->update([
                'locked_until' => now()->addMinutes(self::LOCK_MINUTES),
                'failed_login_attempts' => 0,
            ]);

            $this->events->dispatch(
                new AccountLocked(
                    user: $user,
                    reason: 'too_many_failed_attempts',
                    lockDurationMinutes: self::LOCK_MINUTES,
                ),
                aggregateType: User::class,
                aggregateId: $user->id
            );
        }
    }

    protected function recordSuccessfulLogin(User $user, Request $request): void
    {
        $agent = new Agent();
        $agent->setUserAgent($request->userAgent() ?? '');

        LoginHistory::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'status' => 'success',
            'auth_method' => 'email',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_type' => $agent->deviceType(),
            'device_name' => $agent->device(),
            'browser' => $agent->browser(),
            'os' => $agent->platform(),
        ]);
    }

    protected function recordFailedLogin(string $identifier, string $reason, Request $request): void
    {
        LoginHistory::create([
            'email' => $identifier,
            'status' => 'failed',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'failure_reason' => $reason,
        ]);
    }
}
