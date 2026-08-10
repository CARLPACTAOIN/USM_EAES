<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckQueueHealth extends Command
{
    protected $signature = 'eaes:queue-health';

    protected $description = 'Check Redis reachability and EAES queue backlog thresholds.';

    public function handle(): int
    {
        $errors = [];
        $queueConnection = config('queue.default');
        $cacheStore = config('cache.default');
        $redisRequired = $queueConnection === 'redis' || $cacheStore === 'redis';
        $thresholds = config('services.eaes.queue_health');

        if ($redisRequired) {
            try {
                $response = Redis::connection()->ping();
                if (! in_array(strtoupper((string) $response), ['PONG', '+PONG'], true)) {
                    $errors[] = 'Redis did not return PONG.';
                }

                foreach (['attendance', 'default', 'ai'] as $queue) {
                    $depth = (int) Redis::connection()->lLen('queues:'.$queue);
                    $this->line("{$queue} queue depth: {$depth}");

                    if ($depth > $thresholds['max_queue_depth']) {
                        $errors[] = "{$queue} queue depth threshold exceeded.";
                    }
                }
            } catch (Throwable) {
                $errors[] = 'Redis connectivity check failed.';
            }
        }

        if (Schema::hasTable('queue_outbox_entries')) {
            $pending = DB::table('queue_outbox_entries')->whereNull('dispatched_at')->count();
            $oldest = DB::table('queue_outbox_entries')->whereNull('dispatched_at')->min('created_at');
            $oldestAge = $oldest ? Carbon::parse($oldest)->diffInSeconds(now()) : 0;

            $this->line("Pending queue outbox entries: {$pending}");
            $this->line("Oldest pending queue outbox age: {$oldestAge}s");

            if ($pending > $thresholds['max_pending_outbox_entries']) {
                $errors[] = 'Pending queue outbox threshold exceeded.';
            }

            if ($oldestAge > $thresholds['max_outbox_age_seconds']) {
                $errors[] = 'Queue outbox age threshold exceeded.';
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            $recentFailedJobs = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHour())
                ->count();
            $this->line("Failed jobs in the last hour: {$recentFailedJobs}");

            if ($recentFailedJobs > config('services.eaes.queue_health.max_recent_failed_jobs')) {
                $errors[] = 'Recent failed job threshold exceeded.';
            }
        }

        foreach ($errors as $error) {
            $this->error('[fail] '.$error);
        }

        if ($errors !== []) {
            return self::FAILURE;
        }

        $this->info('Queue health check passed.');

        return self::SUCCESS;
    }
}
