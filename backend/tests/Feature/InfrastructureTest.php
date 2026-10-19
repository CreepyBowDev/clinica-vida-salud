<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InfrastructureTest extends TestCase
{
    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_csrf_endpoint_sets_session_cookies_for_the_frontend(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost:5173')
            ->get('/sanctum/csrf-cookie');

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertCookie('XSRF-TOKEN')
            ->assertCookie('clinica_vida_salud_session');

        $session = $response->getCookie('clinica_vida_salud_session', false);
        $xsrf = $response->getCookie('XSRF-TOKEN', false);

        $this->assertTrue($session->isHttpOnly());
        $this->assertSame('lax', $session->getSameSite());
        $this->assertFalse($xsrf->isHttpOnly());
    }

    public function test_cors_preflight_accepts_only_the_configured_frontend(): void
    {
        $headers = ['Access-Control-Request-Method' => 'POST'];

        $this->options('/api/infrastructure-check', [], [
            ...$headers,
            'Origin' => 'http://localhost:5173',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        // Con un origen único, la librería devuelve ese valor fijo. El navegador
        // rechaza el origen ajeno porque no coincide con Allow-Origin.
        $this->options('/api/infrastructure-check', [], [
            ...$headers,
            'Origin' => 'https://untrusted.example.test',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_stateful_api_rejects_writes_without_csrf_and_accepts_a_valid_token(): void
    {
        // Ruta solo de prueba: no se registra ningún endpoint de negocio.
        Route::middleware('api')->post('/api/infrastructure-check', fn () => response()->noContent());

        // Laravel omite CSRF en testing; local permite ejercitar el middleware real.
        $this->app['env'] = 'local';

        $response = $this->withHeader('Origin', 'http://localhost:5173')
            ->get('/sanctum/csrf-cookie');

        $this->withUnencryptedCookies([
            'clinica_vida_salud_session' => $response->getCookie('clinica_vida_salud_session', false)->getValue(),
            'XSRF-TOKEN' => $response->getCookie('XSRF-TOKEN', false)->getValue(),
        ])->postJson('/api/infrastructure-check')->assertStatus(419);

        $this->withHeader('X-XSRF-TOKEN', $response->getCookie('XSRF-TOKEN', false)->getValue())
            ->postJson('/api/infrastructure-check')->assertNoContent();
    }
}
