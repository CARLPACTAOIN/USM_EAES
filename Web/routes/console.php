<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\AnalyzeEventSentimentsJob;
use App\Models\Event;
use App\Support\EvaluationWindow;

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
        if (!EvaluationWindow::isClosed($event)) {
            continue;
        }

        AnalyzeEventSentimentsJob::dispatch($event->id);
        $dispatched++;
    }

    $this->info("Dispatched {$dispatched} sentiment analysis job(s).");
})->purpose('Dispatch sentiment analysis jobs for events whose evaluation windows have closed');

Artisan::command('eaes:deployment-check {--profile= : demo or production}', function () {
    $profile = $this->option('profile') ?: (config('app.env') === 'production' ? 'production' : 'demo');
    $profile = in_array($profile, ['demo', 'production'], true) ? $profile : 'demo';
    $errors = [];
    $warnings = [];
    $isProduction = $profile === 'production';
    $appUrl = (string) config('app.url');
    $queueConnection = (string) config('queue.default');
    $cacheStore = (string) config('cache.default');
    $sanctumDomains = array_filter(config('sanctum.stateful', []));

    if (config('app.debug')) {
        $errors[] = 'APP_DEBUG must be false for demo/production readiness.';
    }

    if (!str_starts_with($appUrl, 'https://')) {
        $errors[] = 'APP_URL must use https:// for encrypted transport.';
    }

    if (!config('session.secure')) {
        $errors[] = 'SESSION_SECURE_COOKIE must be true.';
    }

    if (!config('session.encrypt')) {
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

    if (!$isProduction && !in_array($queueConnection, ['database', 'redis'], true)) {
        $errors[] = 'Demo queue connection must be database or redis.';
    }

    if ($isProduction && $cacheStore !== 'redis') {
        $errors[] = 'Production cache store must be redis.';
    }

    if (!$isProduction && !in_array($cacheStore, ['database', 'redis', 'file', 'array'], true)) {
        $errors[] = 'Demo cache store must be database, redis, file, or array.';
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
    if (!$storageLinkReady) {
        $message = 'Run php artisan storage:link before serving uploaded proposal files.';
        $isProduction ? $errors[] = $message : $warnings[] = $message;
    }

    if (empty(config('services.gemini.api_key'))) {
        $message = 'GEMINI_API_KEY is empty; AI sentiment/NLP calls will use fallback behavior or fail gracefully.';
        $isProduction ? $errors[] = $message : $warnings[] = $message;
    }

    $warnings[] = 'Ensure a queue worker and scheduler are running: php artisan queue:work and php artisan schedule:work.';

    foreach ($warnings as $warning) {
        $this->warn('[warn] ' . $warning);
    }

    foreach ($errors as $error) {
        $this->error('[fail] ' . $error);
    }

    if (!empty($errors)) {
        $this->error("Deployment check failed for {$profile} profile.");
        return 1;
    }

    $this->info("Deployment check passed for {$profile} profile.");
    return 0;
})->purpose('Validate EAES demo/production deployment readiness');

Schedule::command('eaes:analyze-closed-evaluations')->hourly();
