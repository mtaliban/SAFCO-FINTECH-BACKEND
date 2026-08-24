<?php

namespace App\Providers;

use App\Events\User\LoginFailed;
use App\Events\User\OtpRequested;
use App\Events\User\PasswordResetRequested;
use App\Events\User\ProfilePictureUploaded;
use App\Events\User\UserRegistered;
use App\Listeners\Analytics\LogEventToCsv;
use App\Listeners\Security\DetectSuspiciousLogin;
use App\Listeners\User\GenerateProfileThumbnail;
use App\Listeners\User\SendOtpNotification;
use App\Listeners\User\SendPasswordResetLink;
use App\Listeners\User\SendWelcomeNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        UserRegistered::class => [
            SendWelcomeNotification::class,
            LogEventToCsv::class,
        ],
        OtpRequested::class => [
            SendOtpNotification::class,
            LogEventToCsv::class,
        ],
        PasswordResetRequested::class => [
            SendPasswordResetLink::class,
            LogEventToCsv::class,
        ],
        ProfilePictureUploaded::class => [
            GenerateProfileThumbnail::class,
            LogEventToCsv::class,
        ],
        LoginFailed::class => [
            DetectSuspiciousLogin::class,
            LogEventToCsv::class,
        ],

        // Register the Microsoft Azure driver with Laravel Socialite
        SocialiteWasCalled::class => [
            'SocialiteProviders\\Azure\\AzureExtendSocialite@handle',
        ],
    ];

    public function boot(): void
    {
        //
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
