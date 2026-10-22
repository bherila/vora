@extends('layouts.app')

@section('content')
  <div id="library"></div>
@endsection

@push('scripts')
  @vite(['resources/js/library/page.tsx'])
@endpush
