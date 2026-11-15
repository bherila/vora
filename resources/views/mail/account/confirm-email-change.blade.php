@component('mail::message')
# Confirm your new email address

Someone asked to use this address for their {{ config('app.name') }} account.
The account keeps its current address until you confirm.

@component('mail::button', ['url' => $confirmUrl])
Confirm this address
@endcomponent

This link expires in {{ config('auth.verification.expire', 60) }} minutes. If you
didn't ask for this, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
