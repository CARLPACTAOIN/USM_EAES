<?php

use App\Jobs\AnalyzeEventSentimentsJob;
use App\Models\Event;
use App\Services\EventLifecycleService;
use App\Services\EventNotificationService;
use App\Support\EvaluationWindow;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schedule;
use Predis\Client;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('eaes:analyze-closed-evaluations', function () {
    $events = Event::query()
        ->with('eventDays')
        ->whereIn('status', ['approved', 'completed'])
        ->whereHas('evaluations', function ($query): void {
            $query->where('sentiment', 'unprocessed')
                ->whereNotNull('open_comment')
                ->whereRaw("trim(coalesce(open_comment, '')) <> ''");
        })
        ->get();

    $dispatched = 0;

    foreach ($events as $event) {
        if (! EvaluationWindow::isClosed($event)) {
            continue;
        }

        AnalyzeEventSentimentsJob::dispatch($event->id);
        $dispatched++;
    }

    $this->info("Dispatched {$dispatched} sentiment analysis job(s).");
})->purpose('Dispatch sentiment analysis jobs for events whose evaluation windows have closed');

Artisan::command('eaes:update-event-lifecycles', function (EventLifecycleService $lifecycles) {
    $updated = 0;

    Event::query()
        ->with('eventDays')
        ->whereIn('status', ['approved', 'completed'])
        ->chunkById(100, function ($events) use ($lifecycles, &$updated): void {
            foreach ($events as $event) {
                if ($lifecycles->update($event)) {
                    $updated++;
                }
            }
        });

    $this->info("Updated {$updated} event lifecycle record(s).");
})->purpose('Update approved event lifecycle phases from schedule, evaluation, sync, and processing state');

Artisan::command('eaes:send-participant-scope-reminders', function (EventNotificationService $notifications) {
    $sent = $notifications->sendParticipantScopeReminders();

    $this->info("Sent {$sent} participant invitation reminder notification(s).");
})->purpose('Send in-app reminders for participant invitations and PPAs that still need action');

Artisan::command('eaes:deployment-check {--profile= : demo or production}', function () {
    $profile = $this->option('profile') ?: (config('app.env') === 'production' ? 'production' : 'demo');
    $profile = in_array($profile, ['demo', 'production'], true) ? $profile : 'demo';
    $errors = [];
    $warnings = [];
    $isProduction = $profile === 'production';
    $appUrl = (string) config('app.url');
    $queueConnection = (string) config('queue.default');
    $cacheStore = (string) config('cache.default');
    $redisClient = (string) config('database.redis.client');
    $sanctumDomains = array_filter(config('sanctum.stateful', []));

    if (config('app.debug')) {
        $errors[] = 'APP_DEBUG must be false for demo/production readiness.';
    }

    if (! str_starts_with($appUrl, 'https://')) {
        $errors[] = 'APP_URL must use https:// for encrypted transport.';
    }

    if (! config('session.secure')) {
        $errors[] = 'SESSION_SECURE_COOKIE must be true.';
    }

    if (! config('session.encrypt')) {
        $errors[] = 'SESSION_ENCRYPT must be true.';
    }

    if (config('services.eaes.dev_login_enabled')) {
        if (config('app.env') !== 'local') {
            $errors[] = 'EAES_ENABLE_DEV_LOGIN must be false outside local environments.';
        } else {
            $warnings[] = 'Developer login is enabled; use only for local walkthroughs.';
        }
    }

    if ($isProduction && $queueConnection !== 'redis') {
        $errors[] = 'Production queue connection must be redis.';
    }

    if (! $isProduction && ! in_array($queueConnection, ['database', 'redis'], true)) {
        $errors[] = 'Demo queue connection must be database or redis.';
    }

    if ($isProduction && $cacheStore !== 'redis') {
        $errors[] = 'Production cache store must be redis.';
    }

    if (! $isProduction && ! in_array($cacheStore, ['database', 'redis', 'file', 'array'], true)) {
        $errors[] = 'Demo cache store must be database, redis, file, or array.';
    }

    if ($queueConnection === 'redis' || $cacheStore === 'redis') {
        $redisClientReady = match ($redisClient) {
            'predis' => class_exists(Client::class),
            'phpredis' => extension_loaded('redis'),
            default => false,
        };

        if (! $redisClientReady) {
            $errors[] = "Redis is configured but REDIS_CLIENT={$redisClient} is not available to PHP.";
        } else {
            try {
                Redis::connection()->ping();
            } catch (Throwable) {
                $errors[] = 'Redis connectivity check failed.';
            }
        }
    }

    $retryAfter = (int) config("queue.connections.{$queueConnection}.retry_after", 0);
    if (in_array($queueConnection, ['database', 'redis'], true) && $retryAfter <= 180) {
        $errors[] = 'Queue retry_after must exceed the longest EAES job timeout of 180 seconds.';
    }

    if ($isProduction && ! config('services.eaes.queue_worker_managed')) {
        $errors[] = 'EAES_QUEUE_WORKER_MANAGED must be true when production workers are supervised.';
    }

    if ($isProduction && ! config('services.eaes.scheduler_managed')) {
        $errors[] = 'EAES_SCHEDULER_MANAGED must be true when the production scheduler is supervised.';
    }

    if (empty($sanctumDomains)) {
        $errors[] = 'SANCTUM_STATEFUL_DOMAINS must include the deployed web origin.';
    }

    $localSanctumDomain = collect($sanctumDomains)->contains(function (string $domain): bool {
        return str_contains($domain, 'localhost') || str_contains($domain, '127.0.0.1');
    });

    if ($isProduction && $localSanctumDomain) {
        $errors[] = 'Production Sanctum domains must not include localhost or 127.0.0.1.';
    }

    $storageLinkReady = is_link(public_path('storage')) || is_dir(public_path('storage'));
    if (! $storageLinkReady) {
        $message = 'Run php artisan storage:link before serving uploaded proposal files.';
        $isProduction ? $errors[] = $message : $warnings[] = $message;
    }

    if (empty(config('services.gemini.api_key'))) {
        $message = 'GEMINI_API_KEY is empty; AI sentiment/NLP calls will use fallback behavior or fail gracefully.';
        $isProduction ? $errors[] = $message : $warnings[] = $message;
    }

    $warnings[] = 'Use the EAES Supervisor configuration for attendance, default, AI, and scheduler processes.';

    foreach ($warnings as $warning) {
        $this->warn('[warn] '.$warning);
    }

    foreach ($errors as $error) {
        $this->error('[fail] '.$error);
    }

    if (! empty($errors)) {
        $this->error("Deployment check failed for {$profile} profile.");

        return 1;
    }

    $this->info("Deployment check passed for {$profile} profile.");

    return 0;
})->purpose('Validate EAES demo/production deployment readiness');

Schedule::command('eaes:analyze-closed-evaluations')->hourly();
Schedule::command('eaes:update-event-lifecycles')->everyFifteenMinutes();
Schedule::command('eaes:send-participant-scope-reminders')->dailyAt('08:00');
Schedule::command('eaes:dispatch-queue-outbox')->everyMinute()->withoutOverlapping()->onOneServer();
