<?php

namespace App\Listeners\User;

use App\Events\User\UserRegistered;
use App\Models\User;
use App\Services\Notifications\Channels\InAppChannel;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyAdminsOfNewRegistration implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly InAppChannel $inApp) {}

    public function handle(UserRegistered $event): void
    {
        $newUser = $event->user->fresh('profile', 'roles');
        $roles   = $newUser->roles->pluck('name')->join(', ');

        User::role('system_admin')->each(function (User $admin) use ($newUser, $roles) {
            try {
                $this->inApp->send($admin, 'user.new_registration', [
                    'user_name'      => $newUser->profile?->full_name ?? $newUser->email,
                    'user_email'     => $newUser->email,
                    'roles'          => $roles ?: 'student',
                    'registered_at'  => $newUser->created_at?->toDateTimeString(),
                    'action_url'     => '/admin/users',
                    'action_label'   => 'Angalia Watumiaji',
                ]);
            } catch (\Throwable) {}
        });
    }
}
