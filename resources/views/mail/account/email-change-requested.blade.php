@component('mail::message')
# Your email address is being changed

A request was made to change the email address on your {{ config('app.name') }}
account to **{{ $newEmail }}**. The change takes effect only after that address
confirms it.

If you didn't make this request, sign in and change your password now, and
contact an administrator.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
