<?php

use App\Console\Commands\QueueHealthCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep abandoned (never-completed) uploads hourly.
Schedule::command('media:prune-orphans')->hourly();

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
