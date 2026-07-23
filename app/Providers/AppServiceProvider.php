<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        
        $host = request()->header('X-Forwarded-Host', request()->header('Host', ''));
        if (str_contains($host, 'web-support-portal.lmd.co.id')) {
            \Illuminate\Support\Facades\URL::forceRootUrl('https://web-support-portal.lmd.co.id/clickup');
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
