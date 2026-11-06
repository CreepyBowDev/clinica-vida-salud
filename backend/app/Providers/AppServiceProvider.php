<?php

namespace App\Providers;

use Clinica\shared\utils\BrevoHttpTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('pagos-stripe', function ($request) {
            $actor = (string) $request->user()->getAuthIdentifier();
            $inicio = $request->route('obligacion') !== null || $request->routeIs('pagos.stripe.cierre', 'pagos.paypal.captura');
            $objetivo = (string) ($request->route('obligacion') ?? $request->route('intento'));

            return [Limit::perMinute($inicio ? 10 : 30)->by('pagos:usuario:'.$actor.':'.($inicio ? 'inicio' : 'resultado')),
                Limit::perMinute($inicio ? 3 : 6)->by('pagos:recurso:'.$actor.':'.$objetivo)];
        });
        $this->loadMigrationsFrom(base_path('src/data/database/migrations'));
        Mail::extend('brevo-http', fn () => new BrevoHttpTransport(
            $this->app->make(Factory::class), (string) config('services.brevo.key'),
        ));
    }
}
