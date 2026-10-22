@extends('layouts.app')

@section('content')
  <div class="mx-auto max-w-3xl space-y-6">
    <h1 class="text-3xl font-semibold">{{ config('app.name') }}</h1>
    <p class="text-muted-foreground">Share photos and videos from your events, browse attendee galleries, and collect your favorites.</p>
    <p>Log in to see events shared with you. New accounts need email verification and approval before joining.</p>
    <a class="underline underline-offset-4" href="{{ route('login') }}">Log in</a>
  </div>
@endsection
