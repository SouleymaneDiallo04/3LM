<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // §5.3 : implémentations substituables — BAN en source primaire,
        // Nominatim auto-hébergé en repli (bascule par configuration).
        $this->app->bind(
            \App\Services\Geocoding\Contracts\Geocoder::class,
            \App\Services\Geocoding\BanGeocoder::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // §7 : limitation de débit de l'API — 60 req/min par token
        // (par utilisateur authentifié, sinon par adresse IP).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
