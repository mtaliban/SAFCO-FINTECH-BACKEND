<?php

namespace App\Services\Auth;

use App\Events\User\UserLoggedIn;
use App\Events\User\UserRegistered;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\EventBus\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

/**
 * OAuth login via Google / Microsoft (Azure AD).
 * Uses stateless mode because the API is called from a SPA/mobile app.
 */
class SocialAuthService
{
    /**
     * We accept human-friendly names in the URL (google, microsoft) but
     * translate 'microsoft' to Socialite's actual driver key 'azure'
     * (provided by SocialiteProviders/Microsoft-Azure).
     */
    protected array $allowedProviders = ['google', 'microsoft'];

    protected const DRIVER_ALIAS = [
        'microsoft' => 'azure',
    ];

    public function __construct(protected EventDispatcher $events)
    {
    }

    protected function driver(string $provider): string
    {
        return self::DRIVER_ALIAS[$provider] ?? $provider;
    }

    public function redirectUrl(string $provider): string
    {
        $this->assertAllowed($provider);

        return Socialite::driver($this->driver($provider))
            ->stateless()
            ->redirect()
            ->getTargetUrl();
    }

    /**
     * Handle the OAuth callback. Either logs in an existing user or
     * creates a new one, then issues a Sanctum token.
     */
    public function handleCallback(string $provider, ?string $deviceName = 'social'): array
    {
        $this->assertAllowed($provider);

        $socialUser = Socialite::driver($this->driver($provider))->stateless()->user();

        $user = $this->findOrCreateUser($provider, $socialUser);

        $this->events->dispatch(
            new UserLoggedIn($user, $provider),
            aggregateType: User::class,
            aggregateId: $user->id
        );

        $expiryMinutes = (int) (config('sanctum.expiration') ?: 1440);
        $token = $user->createToken(
            $deviceName,
            ['*'],
            now()->addMinutes($expiryMinutes)
        );

        return [
            'user' => $user->fresh(['profile', 'organization']),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'is_new_user' => $user->wasRecentlyCreated,
        ];
    }

    protected function findOrCreateUser(string $provider, SocialiteUser $socialUser): User
    {
        return DB::transaction(function () use ($provider, $socialUser) {
            $user = User::where('auth_provider', $provider)
                ->where('provider_id', $socialUser->getId())
                ->orWhere('email', $socialUser->getEmail())
                ->first();

            if ($user) {
                $user->update([
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'last_login_at' => now(),
                ]);
                return $user;
            }

            $user = User::create([
                'email' => $socialUser->getEmail(),
                'password' => null,
                'auth_provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            UserProfile::create([
                'user_id' => $user->id,
                'full_name' => $socialUser->getName() ?? Str::before($socialUser->getEmail(), '@'),
                'profile_picture' => $socialUser->getAvatar(),
            ]);

            $user->assignRole('student');

            $this->events->dispatch(
                new UserRegistered($user, $provider),
                aggregateType: User::class,
                aggregateId: $user->id
            );

            return $user;
        });
    }

    protected function assertAllowed(string $provider): void
    {
        if (! in_array($provider, $this->allowedProviders, true)) {
            throw new \InvalidArgumentException("Unsupported provider: {$provider}");
        }
    }
}
