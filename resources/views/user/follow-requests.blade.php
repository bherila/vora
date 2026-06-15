@extends('layouts.app')

@section('content')
  <div id="follow-requests"></div>
@endsection

@push('scripts')
  @vite(['resources/js/user/follow-requests.tsx'])
@endpush
