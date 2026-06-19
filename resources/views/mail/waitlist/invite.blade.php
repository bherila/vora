@component('mail::message')
# You're invited to join

Good news — your request to join {{ config('app.name') }} has been approved. Use
the button below to create your account. This link is tied to your email address
and is valid for {{ \App\Services\WaitlistService::ADMIT_INVITE_LIFETIME_DAYS }} days.

@component('mail::button', ['url' => $inviteUrl])
Create my account
@endcomponent

Because we've already reviewed your request, your account is ready to use as soon
as you finish signing up — no further approval needed.

Welcome aboard,<br>
{{ config('app.name') }}
@endcomponent
