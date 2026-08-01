<?php

namespace Tests\Feature\Console;

use App\Console\Commands\QueueHealthCommand;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $this->assertStringContainsString('"violations":[]', $output);

        Cache::forever(QueueHealthCommand::HEARTBEAT_KEY, now()->subMinutes(4)->toIso8601String());
        $this->assertSame(1, Artisan::call('ops:queue-health', ['--json' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('"healthy":false', $output);
        $this->assertStringContainsString('"scheduler_heartbeat_stale"', $output);
    }

    public function test_health_diagnostic_rejects_a_stale_queue_backlog(): void
    {
        Cache::forever(QueueHealthCommand::HEARTBEAT_KEY, now()->toIso8601String());
        DB::table('jobs')->insert([
            'queue' => 'chat-notifications',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subSeconds(QueueHealthCommand::MAX_QUEUE_AGE_SECONDS + 1)->timestamp,
            'created_at' => now()->subSeconds(QueueHealthCommand::MAX_QUEUE_AGE_SECONDS + 1)->timestamp,
        ]);

        $this->assertSame(1, Artisan::call('ops:queue-health', ['--json' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('"queue_chat-notifications_stale"', $output);
        $this->assertStringContainsString('"stale":true', $output);
    }

    public function test_health_diagnostic_allows_a_fresh_queue_backlog(): void
    {
        Cache::forever(QueueHealthCommand::HEARTBEAT_KEY, now()->toIso8601String());
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->assertSame(0, Artisan::call('ops:queue-health', ['--json' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('"healthy":true', $output);
        $this->assertStringContainsString('"pending":1', $output);
        $this->assertStringContainsString('"stale":false', $output);
    }

    public function test_health_diagnostic_rejects_failed_jobs(): void
    {
        Cache::forever(QueueHealthCommand::HEARTBEAT_KEY, now()->toIso8601String());
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'chat-notifications',
            'payload' => '{}',
            'exception' => 'Synthetic test failure',
            'failed_at' => now(),
        ]);

        $this->assertSame(1, Artisan::call('ops:queue-health', ['--json' => true]));
        $this->assertStringContainsString('"failed_jobs_present"', Artisan::output());
    }
}
