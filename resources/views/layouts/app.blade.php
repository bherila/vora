<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    @viteReactRefresh
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    <meta name="color-scheme" content="dark light">
    <script @cspNonce>
      (function() {
        try {
          var theme = localStorage.getItem('theme') || 'system';
          var d = document.documentElement;
          var isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
          if (isDark) d.classList.add('dark'); else d.classList.remove('dark');
        } catch (e) { /* no-op */ }
      })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/navbar.tsx'])
    @stack('head')
    <script @cspNonce>(_=>{let a})()</script>
  </head>
  <body class="min-h-screen flex flex-col">
    @php
      $__currentUser = auth()->user();
      $__isAuthenticated = ! is_null($__currentUser);
      $__isAdmin = $__isAuthenticated && $__currentUser->isAdmin();
      // Mirror the follow-request inbox filter (requester still active) so the
      // badge never counts requests the inbox hides.
      $__followRequestCount = $__isAuthenticated ? $__currentUser->receivedFollowRequests()->where('status', 'pending')->whereHas('requester', fn ($q) => $q->active())->count() : 0;
      // Mirror the invite-inbox filter (pendingForActiveOwner) so the badge count
      // never includes invites the inbox hides (owner since gone inactive).
      $__authorshipInviteCount = $__isAuthenticated ? $__currentUser->storyAuthorships()->pendingForActiveOwner()->count() : 0;
      $__navbarInitialData = json_encode([
        'authenticated' => $__isAuthenticated,
        'isAdmin' => $__isAdmin,
        'requestCount' => $__followRequestCount + $__authorshipInviteCount,
      ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    @endphp
    <script id="navbar-initial-data" type="application/json" @cspNonce>
      {!! $__navbarInitialData !!}
    </script>
    <header class="site-header border-b border-gray-200 dark:border-[#3E3E3A] h-14">
      <div id="navbar"></div>
    </header>

    <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
      @yield('content')
    </main>

    <footer class="border-t border-gray-200 dark:border-[#3E3E3A] py-6 text-sm text-center text-gray-600 dark:text-[#A1A09A]">
      <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-2">
        <span>© {{ date('Y') }} {{ config('app.name') }}</span>
        @foreach ($footerPages ?? [] as $footerPage)
          <a class="underline-offset-4 hover:underline" href="{{ $footerPage['url'] }}">{{ $footerPage['label'] }}</a>
        @endforeach
      </div>
    </footer>

    @stack('scripts')
  </body>
</html>
