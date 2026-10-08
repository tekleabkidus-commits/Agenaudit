<?php

namespace App\Providers;

use App\Services\AI\HttpVisionExtractor;
use App\Services\AI\GeminiVisionExtractor;
use App\Services\AI\OpenAIVisionExtractor;
use App\Services\AI\VisionExtractorInterface;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VisionExtractorInterface::class, function () {
            return match ((string) config('services.ai.driver', 'http')) {
                'openai' => app(OpenAIVisionExtractor::class),
                'gemini' => app(GeminiVisionExtractor::class),
                'http' => app(HttpVisionExtractor::class),
                default => throw new \RuntimeException('Unsupported AI_DRIVER. Use openai, gemini, or http.'),
            };
        });
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(8)->by(strtolower((string) $request->input('username')).'|'.$request->ip());
        });

        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(30)->by((string) $request->user()?->id.'|'.$request->ip());
        });
    }
}
