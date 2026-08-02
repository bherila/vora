@extends('layouts.app')

@section('content')
  <div id="interest-page"></div>
@endsection

@push('scripts')
  @vite(['resources/js/interests/page.tsx'])
@endpush
