@extends('layouts.app')

@section('content')
  <article class="prose prose-slate mx-auto max-w-4xl dark:prose-invert">
    <h1>{{ $title }}</h1>
    {!! $html !!}
  </article>
@endsection
