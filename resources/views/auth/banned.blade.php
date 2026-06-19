@extends('layouts.app')

@section('content')
  <div id="banned"></div>

  <form method="POST" action="{{ route('logout') }}" class="mx-auto mt-3 max-w-md px-4 text-center">
    @csrf
    <button type="submit" class="text-sm text-muted-foreground underline-offset-4 hover:underline">
      Log out
    </button>
  </form>
@endsection

@push('scripts')
  @vite(['resources/js/auth/banned.tsx'])
@endpush
