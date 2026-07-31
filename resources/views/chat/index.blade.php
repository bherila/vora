@extends('layouts.app')

@section('content')
  <div id="chat-page"></div>
@endsection

@push('scripts')
  @vite(['resources/js/chat/page.tsx'])
@endpush
