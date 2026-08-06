@component('mail::message')
# Password Reset Request

Hello {{ $user->profile?->first_name ?? '' }},

We received a request to reset your password. Click the button below to choose a new one:

@component('mail::button', ['url' => $resetUrl])
Reset Password
@endcomponent

This link will expire in **{{ $expiresIn }} minutes**.

If you did not request a password reset, no further action is needed.

Regards,<br>
{{ config('app.name') }}
@endcomponent
