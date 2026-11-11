@extends('layouts.app')

@section('content')
  <main class="mx-auto max-w-md space-y-4 px-4 py-12">
    <h1 class="text-2xl font-bold">Emergency administrator access</h1>
    <p>For an explicitly designated local recovery administrator. Your password and email verification are required.</p>
    @if ($errors->any())
      <div role="alert">{{ $errors->first() }}</div>
    @endif
    @if (isset($attemptToken))
      <form method="POST" action="{{ route('emergency.verify') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="attempt_token" value="{{ $attemptToken }}">
        <label class="block">Email verification code
          <input name="code" required inputmode="numeric" autocomplete="one-time-code" minlength="6" maxlength="6" class="block w-full rounded border bg-background px-3 py-2">
        </label>
        <button type="submit" class="rounded bg-primary px-4 py-2 text-primary-foreground">Verify and sign in</button>
      </form>
    @else
      <form method="POST" action="{{ route('emergency.store') }}" class="space-y-4">
        @csrf
        <label class="block">Email
          <input type="email" name="email" required autocomplete="username" value="{{ old('email') }}" class="block w-full rounded border bg-background px-3 py-2">
        </label>
        <label class="block">Password
          <input type="password" name="password" required autocomplete="current-password" class="block w-full rounded border bg-background px-3 py-2">
        </label>
        <button type="submit" class="rounded bg-primary px-4 py-2 text-primary-foreground">Continue with email verification</button>
      </form>
    @endif
  </main>
@endsection
