@extends('layouts.app')

@section('content')
  <div class="mx-auto max-w-3xl px-4 py-8">
    {{-- The single-post React surface lands with the posts frontend; this page
         resolves the ulid so notification links never 404 in the meantime. --}}
    <div id="post-view" data-ulid="{{ $ulid }}">
      <p class="text-muted-foreground">Loading post…</p>
    </div>
  </div>
@endsection
