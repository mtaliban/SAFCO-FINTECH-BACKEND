<?php

namespace App\Services\Auth;

use App\Events\User\PasswordResetCompleted;
use App\Events\User\PasswordResetRequested;
use App\Models\User;
use App\Services\EventBus\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetService
{
    public function __construct(protected EventDispatcher $events)
    {
    }

    /**
     * Request a password reset link. Always returns success (even for
     * unknown emails) to avoid leaking whether an account exists.
     */
    public function request(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return; // silent success
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'email' => $email,
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        $this->events->dispatch(
            new PasswordResetRequested($user, $token, 'email'),
            aggregateType: User::class,
            aggregateId: $user->id
        );
    }

    /**
     * Complete the password reset. Requires the plaintext token that was
     * sent to the user's email/SMS.
     */
    public function reset(string $email, string $token, string $newPassword): bool
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $row || ! Hash::check($token, $row->token)) {
            return false;
        }

        // Token expires after 60 minutes
        if (now()->diffInMinutes($row->created_at) > 60) {
            return false;
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            return false;
        }

        DB::transaction(function () use ($user, $newPassword, $email) {
            $user->update([
                'password' => Hash::make($newPassword),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]);

            $user->tokens()->delete(); // invalidate all Sanctum sessions

            DB::table('password_reset_tokens')->where('email', $email)->delete();

            $this->events->dispatch(
                new PasswordResetCompleted($user),
                aggregateType: User::class,
                aggregateId: $user->id
            );
        });

        return true;
    }
}
