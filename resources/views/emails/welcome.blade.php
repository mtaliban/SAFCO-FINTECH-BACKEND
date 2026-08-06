@component('mail::message')
# Karibu, {{ $user->profile?->first_name ?? 'Mwanafunzi' }}!

Asante kwa kujiunga na **SAFCO FINTECH Learning Management System** — jukwaa la kisasa la mafunzo ya kitaaluma Tanzania.

Sasa unaweza kuanza safari yako ya kujifunza:

- 📚 Excel, Power Query, Power BI
- 💰 Accounting, Finance & IFRS
- 🖥️ Coding & ERP Systems
- 🎯 Live Kahoot-style Quizzes

@component('mail::button', ['url' => $frontendUrl])
Nenda Kwenye Dashboard
@endcomponent

Kama una maswali yoyote, tuko hapa kukusaidia.

Karibu tena,<br>
**Timu ya {{ config('app.name') }}**
@endcomponent
