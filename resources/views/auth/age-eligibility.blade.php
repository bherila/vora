@extends('layouts.app')

@section('content')
  <div class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-2xl font-semibold">Account age requirements</h1>
    @if ($reason === 'minimum_age')
      <p>This site requires account holders to be at least {{ $policy['minimum_age'] }} years old. Your recorded birth date does not yet meet that requirement, or is missing.</p>
    @else
      <p>An administrator must confirm your age before you can access shared content. Contact the organizer to arrange confirmation. Do not upload identity documents here.</p>
    @endif
    <p>You can still manage, export, deactivate or delete your account in Settings. Contact an administrator if your recorded birth date is incorrect.</p>
    <div class="flex flex-wrap gap-4">
      <a href="{{ route('age.eligibility') }}" class="underline">Check again and continue</a>
      <a href="{{ route('user.settings') }}" class="underline">Account settings</a>
    </div>
    <form action="/logout" method="POST">@csrf<button type="submit" class="underline">Log out</button></form>
  </div>
@endsection
