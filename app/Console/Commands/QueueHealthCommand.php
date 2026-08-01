<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class QueueHealthCommand extends Command
{
    public const HEARTBEAT_KEY = 'operations:scheduler-heartbeat';

    public const MAX_HEARTBEAT_AGE_SECONDS = 180;

    public const MAX_QUEUE_AGE_SECONDS = 300;

    public const MAX_FAILED_JOBS = 0;

    protected $signature = 'ops:queue-health {--json : Emit machine-readable JSON}';

    protected $description = 'Report scheduler freshness and database queue backlog health';

    public function handle(): int
    {
        try {
            $heartbeat = Cache::get(self::HEARTBEAT_KEY);
            $heartbeatAt = is_string($heartbeat) ? Carbon::parse($heartbeat) : null;
            $heartbeatAge = $heartbeatAt === null
                ? null
                : (int) floor($heartbeatAt->diffInSeconds(now()));
            $queues = collect(['chat-notifications', 'default'])->mapWithKeys(function (string $queue): array {
                $createdAt = DB::table('jobs')->where('queue', $queue)->min('created_at');
                $oldestAge = $createdAt === null ? null : max(0, now()->timestamp - (int) $createdAt);

                return [$queue => [
                    'pending' => DB::table('jobs')->where('queue', $queue)->count(),
                    'oldest_age_seconds' => $oldestAge,
                    'stale' => $oldestAge !== null && $oldestAge > self::MAX_QUEUE_AGE_SECONDS,
                ]];
            })->all();
            $failedCount = DB::table('failed_jobs')->count();
            $recentFailure = DB::table('failed_jobs')->max('failed_at');
            $violations = [];

            if ($heartbeatAge === null || $heartbeatAge > self::MAX_HEARTBEAT_AGE_SECONDS) {
                $violations[] = 'scheduler_heartbeat_stale';
            }

            foreach ($queues as $queue => $health) {
                if ($health['stale']) {
                    $violations[] = "queue_{$queue}_stale";
                }
            }

            if ($failedCount > self::MAX_FAILED_JOBS) {
                $violations[] = 'failed_jobs_present';
            }

            $healthy = $violations === [];

            $payload = [
                'healthy' => $healthy,
                'violations' => $violations,
                'thresholds' => [
                    'heartbeat_max_age_seconds' => self::MAX_HEARTBEAT_AGE_SECONDS,
                    'queue_max_age_seconds' => self::MAX_QUEUE_AGE_SECONDS,
                    'failed_jobs_max_count' => self::MAX_FAILED_JOBS,
                ],
                'scheduler' => [
                    'last_heartbeat_at' => $heartbeatAt?->toIso8601String(),
                    'age_seconds' => $heartbeatAge,
                ],
                'queues' => $queues,
                'failed_jobs' => [
                    'count' => $failedCount,
                    'most_recent_at' => $recentFailure,
                ],
            ];
        } catch (Throwable $exception) {
            $payload = ['healthy' => false, 'error' => $exception->getMessage()];
            $healthy = false;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->components->info($healthy ? 'Queue operations are healthy.' : 'Queue operations need attention.');
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
