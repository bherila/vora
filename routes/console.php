<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep abandoned (never-completed) uploads hourly.
Schedule::command('media:prune-orphans')->hourly();

// Drain the database queue (web push delivery, follower fan-out) on shared
// hosting, where a long-running `queue:work` daemon isn't available. Each run
// processes pending jobs and exits; --max-time caps the run well under the
// 3-minute interval so withoutOverlapping never has to block the next tick.
Schedule::command('queue:work --stop-when-empty --max-time=160 --tries=3')
    ->everyThreeMinutes()
    ->withoutOverlapping();
