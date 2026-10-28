<?php

use App\Console\Commands\QueueHealthCommand;
use App\Models\MailDelivery;
use App\Services\Auth\SignIn;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep abandoned (never-completed) uploads hourly.
Schedule::command('media:prune-orphans')->hourly();

// Email delivery records are operational evidence, not history: keep 90 days.
Schedule::command('model:prune', ['--model' => [MailDelivery::class]])->daily();

// The heartbeat is external evidence that cPanel is actually invoking
// schedule:run; a schedule definition by itself cannot prove that.
Schedule::call(fn () => Cache::forever(QueueHealthCommand::HEARTBEAT_KEY, now()->toIso8601String()))
    ->name('operations:scheduler-heartbeat')
    ->everyMinute();

// Shared hosting has no daemon manager. Drain the database queue for at most
// one cron tick, prioritizing chat wakeups while durable messages remain HTTP/
// database-backed. The two-minute lock expiry recovers quickly after a kill.
Schedule::command('queue:work database --queue=chat-notifications,default --stop-when-empty --max-time=50 --timeout=45 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(2);

// Under provider sign-in, apply people the identity provider deleted. Its lease
// keeps runs from overlapping; a run with a backlog is paced and takes minutes.
Schedule::command('bherila-auth:consume-identity-tombstones')
    ->everyFiveMinutes()
    ->runInBackground()
    ->when(fn (): bool => SignIn::usesProvider());
