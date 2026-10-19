<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);

        if ($app->configurationIsCached()) {
            throw new RuntimeException('Ejecuta php artisan config:clear antes de las pruebas.');
        }

        $app->make(Kernel::class)->bootstrap();

        $connection = $app['config']->get('database.connections.pgsql');

        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'pgsql'
            || $connection['database'] !== 'clinica_vida_salud_test'
            || $connection['username'] !== 'clinica_vida_salud_test'
            || ! empty($connection['url'])) {
            throw new RuntimeException('Las pruebas requieren la base y el rol aislados clinica_vida_salud_test. Revisa .env.testing.');
        }

        // Todas las peticiones externas de tests deben estar simuladas explícitamente.
        Http::preventStrayRequests();

        return $app;
    }
}
