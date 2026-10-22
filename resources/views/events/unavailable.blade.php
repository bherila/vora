<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title>Event unavailable</title>
    @vite(['resources/css/app.css'])
  </head>
  <body class="flex min-h-screen items-center justify-center bg-background p-4 text-foreground">
    <main class="w-full max-w-md space-y-4 rounded-xl border bg-card p-6 text-card-foreground shadow-sm">
      <h1 class="text-2xl font-semibold">Event unavailable</h1>
      <p class="text-sm text-muted-foreground">This event isn’t available to your account. Check the link or ask the organizer for access.</p>
      <a href="{{ route('events.index', [], false) }}" class="inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">Browse events</a>
    </main>
  </body>
</html>
