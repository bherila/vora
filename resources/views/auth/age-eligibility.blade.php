@extends('layouts.app')

@section('content')
  <div class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-2xl font-semibold">Account age requirements</h1>
    @if ($needsBirthDate && $reason === 'minimum_age')
      <p>This site requires account holders to be at least {{ $policy['minimum_age'] }} years old. Enter your birth date to continue. You can only enter it once; contact an administrator if it needs correcting later.</p>
      <form method="POST" action="{{ route('age.birth-date') }}" class="space-y-3">
        @csrf
        <div class="grid max-w-xs gap-1">
          <label for="birth_date" class="text-sm font-medium">Birth date</label>
          <input id="birth_date" name="birth_date" type="date" required max="{{ $policy['latest_birth_date'] }}"
            value="{{ old('birth_date') }}" autocomplete="bday" aria-describedby="birth_date_error"
            class="h-10 rounded-md border border-input bg-background px-3 text-sm">
          @error('birth_date')
            <p id="birth_date_error" role="alert" class="text-sm text-destructive">{{ $message }}</p>
          @enderror
        </div>
        <button type="submit"
          class="inline-flex h-10 items-center justify-center rounded-md bg-primary px-5 text-sm font-medium text-primary-foreground hover:bg-primary/90">
          Continue
        </button>
      </form>
    @elseif ($reason === 'minimum_age')
      <p>This site requires account holders to be at least {{ $policy['minimum_age'] }} years old. Your recorded birth date does not yet meet that requirement, or is missing.</p>
    @else
      <p>An administrator must confirm your age before you can access shared content. Contact the organizer to arrange confirmation. Do not upload identity documents here.</p>
    @endif
    <p>You can still manage, export, deactivate or delete your account in Settings. Contact an administrator if your recorded birth date is incorrect.</p>
    <div class="flex flex-wrap gap-4">
      <a href="{{ route('age.eligibility') }}" class="underline">Check again and continue</a>
      <a href="{{ route('user.settings') }}" class="underline">Account settings</a>
    </div>
    <form action="{{ route('logout') }}" method="POST">@csrf<button type="submit" class="underline">Log out</button></form>
  </div>
@endsection
