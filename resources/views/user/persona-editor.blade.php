@extends('layouts.app')

@section('content')
  <div id="persona-editor"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/persona-editor.tsx'])
@endpush
