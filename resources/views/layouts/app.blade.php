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
    @php
      $__currentUser = auth()->user();
      $__isAuthenticated = ! is_null($__currentUser);
      // Use the same gate the admin routes enforce (admin flag PLUS approved and
      // able to log in), not the bare isAdmin() flag — otherwise a pending or
      // disabled admin would still receive the Admin menu and open-report count
      // here even though every admin route blocks them.
      $__isAdmin = \Illuminate\Support\Facades\Gate::allows('admin-only');
      // Mirror the follow-request inbox filter (requester still active) so the
      // badge never counts requests the inbox hides.
      $__followRequestCount = $__isAuthenticated ? $__currentUser->receivedFollowRequests()->where('status', 'pending')->whereHas('requester', fn ($q) => $q->active())->count() : 0;
      // Mirror the invite-inbox filter (pendingForActiveOwner) so the badge count
      // never includes invites the inbox hides (owner since gone inactive).
      $__authorshipInviteCount = $__isAuthenticated ? $__currentUser->storyAuthorships()->pendingForActiveOwner()->count() : 0;
      $__requestCount = $__followRequestCount + $__authorshipInviteCount;
      $__navItems = array_values(array_filter([
        // Guests see the marketing home; logged-in users land on the feed, so
        // "Home" would just be a redirect — drop it from the authed nav.
        $__isAuthenticated ? null : ['label' => 'Home', 'href' => route('home', [], false)],
        $__isAuthenticated ? ['label' => 'Feed', 'href' => route('feed', [], false)] : null,
        // The profile (/me) hosts the user's media, stories, characters, posts,
        // and favorites.
        $__isAuthenticated ? ['label' => 'Profile', 'href' => route('me', [], false)] : null,
        $__isAuthenticated ? ['label' => 'Explore', 'href' => route('explore', [], false)] : null,
        // Media, characters, and stories are all created and managed on the
        // profile now, so the standalone "Create" nav entry is gone.
        $__isAuthenticated ? ['label' => 'People', 'href' => route('users.directory', [], false)] : null,
        $__isAuthenticated ? ['label' => 'Requests', 'href' => route('users.follow-requests', [], false), 'badge' => $__requestCount] : null,
      ]));
      // Open abuse reports awaiting review, shown as a count on the admin link.
      $__openReportCount = $__isAdmin ? \App\Models\Report::open()->count() : 0;
      $__adminMenu = $__isAdmin ? [
        'label' => 'Admin',
        'items' => [
          ['type' => 'link', 'label' => 'Users', 'href' => route('admin.users', [], false)],
          ['type' => 'link', 'label' => 'Invites & signups', 'href' => route('admin.invites', [], false)],
          ['type' => 'link', 'label' => 'Invitation requests', 'href' => route('admin.waitlist', [], false)],
          ['type' => 'link', 'label' => 'Interests', 'href' => route('admin.interests', [], false)],
          ['type' => 'link', 'label' => 'Media review', 'href' => route('admin.media', [], false)],
          ['type' => 'link', 'label' => 'Abuse reports'.($__openReportCount > 0 ? " ({$__openReportCount})" : ''), 'href' => route('admin.reports', [], false)],
          ['type' => 'link', 'label' => 'Story review', 'href' => route('admin.stories', [], false)],
          ['type' => 'link', 'label' => 'Posts review', 'href' => route('admin.posts', [], false)],
          ['type' => 'link', 'label' => 'Deleted content', 'href' => route('admin.deleted-content', [], false)],
          ['type' => 'link', 'label' => 'Static pages', 'href' => route('admin.pages', [], false)],
          ['type' => 'link', 'label' => 'Audit log', 'href' => route('admin.audit-log', [], false)],
        ],
      ] : null;
      $__accountMenu = $__isAuthenticated ? [
        'label' => $__currentUser->display_name ?: $__currentUser->name,
        'avatarUrl' => \App\Support\UserPresenter::avatarUrl($__currentUser, app(\App\Services\Media\MediaResponseService::class), $__currentUser),
        // The avatar + name link straight to the profile; the caret opens this menu.
        'profileHref' => route('me', [], false),
        'items' => [
          ['type' => 'link', 'label' => 'Settings', 'href' => route('user.settings', [], false)],
          ['type' => 'link', 'label' => 'Invites', 'href' => route('user.invites', [], false)],
          ['type' => 'action', 'label' => 'Log out', 'action' => 'logout'],
        ],
      ] : null;
      $__guestMenuItems = $__isAuthenticated ? [] : [
        ['label' => 'Log in', 'href' => route('login', [], false), 'variant' => 'link'],
        ['label' => 'Sign up', 'href' => route('register', [], false), 'variant' => 'primary'],
      ];
      $__payload = ['navbar' => [
        'brand' => ['label' => config('app.name'), 'href' => route('home', [], false)],
        'authenticated' => $__isAuthenticated,
        'isAdmin' => $__isAdmin,
        'requestCount' => $__requestCount,
        'navItems' => $__navItems,
        'adminMenu' => $__adminMenu,
        'accountMenu' => $__accountMenu,
        'guestMenuItems' => $__guestMenuItems,
      ]] + ($initialData ?? []);
    @endphp
    <script id="initial-data" type="application/json" @cspNonce>{!! json_encode($__payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    @vite(['resources/css/app.css', 'resources/js/navbar.tsx'])
    @stack('head')
    <script @cspNonce>(_=>{let a})()</script>
  </head>
  <body class="min-h-screen flex flex-col">
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
