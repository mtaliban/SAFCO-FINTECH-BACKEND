@component('mail::message')
# Verification Code

Your **{{ $type }}** code is:

@component('mail::panel')
# {{ $code }}
@endcomponent

This code will expire in **{{ $expiresIn }} minutes**.

If you did not request this code, please ignore this email or contact support immediately.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
