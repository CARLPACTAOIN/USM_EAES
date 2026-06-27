<?php

namespace App\Providers;

use App\Services\Contracts\AiServiceInterface;
use App\Services\GeminiService;
use App\Services\OllamaService;
use Illuminate\Support\Facades\URL;
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
    }
}
