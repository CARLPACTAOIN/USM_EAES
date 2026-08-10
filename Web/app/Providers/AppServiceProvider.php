<?php

namespace App\Providers;

use App\Models\Evaluation;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventParticipationScope;
use App\Models\EventScannerAssignment;
use App\Models\OrganizationMembership;
use App\Models\PendingStudentLink;
use App\Models\ScannerSession;
use App\Models\User;
use App\Observers\EventAnalyticsAudienceInvalidationObserver;
use App\Observers\EventAnalyticsCacheInvalidationObserver;
use App\Services\AdminScopeService;
use App\Services\Contracts\AiServiceInterface;
use App\Services\EventNotificationService;
use App\Services\GeminiService;
use App\Services\OllamaService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the active AI provider based on AI_PROVIDER env variable.
        // Switch between 'gemini' and 'ollama' without changing any controller code.
        $this->app->bind(AiServiceInterface::class, function ($app) {
            $provider = config('services.ai.provider', 'gemini');

            return match ($provider) {
                'ollama' => $app->make(OllamaService::class),
                default => $app->make(GeminiService::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::observe(EventAnalyticsCacheInvalidationObserver::class);
        EventDay::observe(EventAnalyticsCacheInvalidationObserver::class);
        EventParticipationScope::observe(EventAnalyticsCacheInvalidationObserver::class);
        EventScannerAssignment::observe(EventAnalyticsCacheInvalidationObserver::class);
        ScannerSession::observe(EventAnalyticsCacheInvalidationObserver::class);
        PendingStudentLink::observe(EventAnalyticsCacheInvalidationObserver::class);
        Evaluation::observe(EventAnalyticsCacheInvalidationObserver::class);
        User::observe(EventAnalyticsAudienceInvalidationObserver::class);
        OrganizationMembership::observe(EventAnalyticsAudienceInvalidationObserver::class);

        RateLimiter::for('api-login', function (Request $request): Limit {
            return Limit::perMinute(config('services.eaes.rate_limits.login_per_minute'))
                ->by('ip:'.$request->ip());
        });

        RateLimiter::for('api-google-login', function (Request $request): Limit {
            return Limit::perMinute(config('services.eaes.rate_limits.google_login_per_minute'))
                ->by('ip:'.$request->ip());
        });

        RateLimiter::for('api-authenticated', function (Request $request): Limit {
            return Limit::perMinute(config('services.eaes.rate_limits.authenticated_per_minute'))
                ->by($this->apiRateLimitKey($request));
        });

        RateLimiter::for('api-scanner-sync', function (Request $request): Limit {
            return Limit::perMinute(config('services.eaes.rate_limits.scanner_sync_per_minute'))
                ->by($this->apiRateLimitKey($request));
        });

        RateLimiter::for('api-write', function (Request $request): Limit {
            return Limit::perMinute(config('services.eaes.rate_limits.write_per_minute'))
                ->by($this->apiRateLimitKey($request));
        });

        RateLimiter::for('api-nlp', function (Request $request): Limit {
            return Limit::perMinute(config('services.eaes.rate_limits.nlp_per_minute'))
                ->by($this->apiRateLimitKey($request));
        });

        if (config('services.eaes.force_https')) {
            URL::forceScheme('https');
        }

        View::composer('components.layouts.app', function ($view): void {
            $summary = [
                'unread_count' => 0,
                'needs_action_count' => 0,
            ];
            $currentAdminScope = null;

            if (auth()->check()
                && Schema::hasTable('notifications')
                && Schema::hasTable('event_participation_scopes')
                && Schema::hasColumn('event_participation_scopes', 'invitation_acknowledged_at')) {
                $user = auth()->user();
                $summary = app(EventNotificationService::class)->summaryForUser($user);
            }

            if (auth()->check() && Schema::hasTable('admin_assignments')) {
                $currentAdminScope = app(AdminScopeService::class)->forUser(auth()->user());
            }

            $view->with([
                'notificationSummary' => $summary,
                'currentAdminScope' => $currentAdminScope,
            ]);
        });
    }

    private function apiRateLimitKey(Request $request): string
    {
        $user = $request->user();
        $tokenId = $user?->currentAccessToken()?->getKey() ?? 'session';
        $userId = $user?->getAuthIdentifier() ?? 'guest';

        return sprintf('user:%s:token:%s:ip:%s', $userId, $tokenId, $request->ip());
    }
}
