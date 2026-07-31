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

    protected $signature = 'ops:queue-health {--json : Emit machine-readable JSON}';

    protected $description = 'Report scheduler freshness and database queue backlog health';

    public function handle(): int
    {
        try {
            $heartbeat = Cache::get(self::HEARTBEAT_KEY);
            $heartbeatAt = is_string($heartbeat) ? Carbon::parse($heartbeat) : null;
            $heartbeatAge = $heartbeatAt?->diffInSeconds(now());
            $queues = collect(['chat-notifications', 'default'])->mapWithKeys(function (string $queue): array {
                $createdAt = DB::table('jobs')->where('queue', $queue)->min('created_at');

                return [$queue => [
                    'pending' => DB::table('jobs')->where('queue', $queue)->count(),
                    'oldest_age_seconds' => $createdAt === null ? null : max(0, now()->timestamp - (int) $createdAt),
                ]];
            })->all();
            $failedCount = DB::table('failed_jobs')->count();
            $recentFailure = DB::table('failed_jobs')->max('failed_at');
            $healthy = $heartbeatAge !== null && $heartbeatAge <= 180;

            $payload = [
                'healthy' => $healthy,
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
