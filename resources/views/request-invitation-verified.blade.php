@extends('layouts.app')

@section('content')
  <div class="flex min-h-screen flex-col items-center justify-center p-4">
    <div class="w-full max-w-md rounded-lg border border-border bg-card p-6 text-center">
      @if ($verified)
        <h1 class="text-2xl font-bold">Email verified</h1>
        <p class="mt-2 text-sm text-muted-foreground">
          Thanks for confirming your email. We&rsquo;ll review your request and email you an
          invitation if you&rsquo;re approved.
        </p>
      @else
        <h1 class="text-2xl font-bold">Link no longer valid</h1>
        <p class="mt-2 text-sm text-muted-foreground">
          This verification link is invalid or has expired. You can request an invitation again.
        </p>
        <a href="{{ route('waitlist.request') }}" class="mt-4 inline-block text-primary hover:underline">
          Request an invitation
        </a>
      @endif
    </div>
  </div>
@endsection
