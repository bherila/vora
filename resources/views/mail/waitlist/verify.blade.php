@component('mail::message')
# Verify your invitation request

Thanks for requesting an invitation to {{ config('app.name') }}. Confirm this email
address to put your request in front of our team.

@component('mail::button', ['url' => $verifyUrl])
Verify my email
@endcomponent

Or enter this code on the verification page:

@component('mail::panel')
# {{ $code }}
@endcomponent

If you didn't request an invitation, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
