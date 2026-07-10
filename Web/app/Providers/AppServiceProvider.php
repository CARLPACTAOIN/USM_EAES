<?php

namespace App\Providers;

use App\Services\Contracts\AiServiceInterface;
use App\Services\EventNotificationService;
use App\Services\GeminiService;
use App\Services\OllamaService;
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
                default  => $app->make(GeminiService::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('services.eaes.force_https')) {
            URL::forceScheme('https');
        }

        View::composer('components.layouts.app', function ($view): void {
            $summary = [
                'unread_count' => 0,
                'needs_action_count' => 0,
            ];

            if (auth()->check()
                && Schema::hasTable('notifications')
                && Schema::hasTable('event_participation_scopes')
                && Schema::hasColumn('event_participation_scopes', 'invitation_acknowledged_at')) {
                $summary = app(EventNotificationService::class)->summaryForUser(auth()->user());
            }

            $view->with('notificationSummary', $summary);
        });
    }
}
