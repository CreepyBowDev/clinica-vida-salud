<?php

namespace App\Providers;

use Clinica\shared\utils\BrevoHttpTransport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Mail;
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
        $this->loadMigrationsFrom(base_path('src/data/database/migrations'));
        Mail::extend('brevo-http', fn () => new BrevoHttpTransport(
            $this->app->make(Factory::class), (string) config('services.brevo.key'),
        ));
    }
}
