<?php

namespace Tests\Feature\Console;

use App\Console\Commands\QueueHealthCommand;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ChatQueueOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_host_worker_is_minute_scoped_prioritized_and_safely_locked(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($candidate): bool => $candidate instanceof Event
                && str_contains((string) $candidate->command, 'queue:work database'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertStringContainsString('--queue=chat-notifications,default', $event->command);
        $this->assertStringContainsString('--stop-when-empty', $event->command);
        $this->assertStringContainsString('--max-time=50', $event->command);
        $this->assertStringContainsString('--timeout=45', $event->command);
        $this->assertStringContainsString('--tries=3', $event->command);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(2, $event->expiresAt);
        $this->assertGreaterThan(45, config('queue.connections.database.retry_after'));
    }

    public function test_health_diagnostic_reports_fresh_and_stale_scheduler_heartbeats(): void
    {
        Cache::forever(QueueHealthCommand::HEARTBEAT_KEY, now()->toIso8601String());
        $this->assertSame(0, Artisan::call('ops:queue-health', ['--json' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('"healthy":true', $output);
        $this->assertStringContainsString('"chat-notifications"', $output);
        $this->assertStringContainsString('"failed_jobs"', $output);

        Cache::forever(QueueHealthCommand::HEARTBEAT_KEY, now()->subMinutes(4)->toIso8601String());
        $this->assertSame(1, Artisan::call('ops:queue-health', ['--json' => true]));
        $this->assertStringContainsString('"healthy":false', Artisan::output());
    }
}
