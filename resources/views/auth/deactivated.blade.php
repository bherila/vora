@extends('layouts.app')

@section('content')
  <div class="mx-auto max-w-md px-4 py-16 text-center">
    <h1 class="text-2xl font-bold">Your account is deactivated</h1>
    <p class="mt-3 text-muted-foreground">
      While deactivated, other users can't see your profile, media, or characters.
      Reactivate any time to restore your account exactly as it was.
    </p>

    <form method="POST" action="{{ route('account.reactivate') }}" class="mt-6">
      @csrf
      <button type="submit"
        class="inline-flex h-10 items-center justify-center rounded-md bg-primary px-5 text-sm font-medium text-primary-foreground hover:bg-primary/90">
        Reactivate account
      </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-3">
      @csrf
      <button type="submit" class="text-sm text-muted-foreground underline-offset-4 hover:underline">
        Log out
      </button>
    </form>
  </div>
@endsection
