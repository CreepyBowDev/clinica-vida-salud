<?php

namespace Tests\Fixtures;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

trait ReportesAisladosFixtures
{
    private function aislarReportes(): void
    {
        // Comprobaciones efectivas ANTES de DDL/fixtures. Nunca se reutiliza un esquema.
        $config = config('database.connections.pgsql');
        $this->assertContains($config['host'], ['127.0.0.1', 'localhost', '::1']);
        $identity = DB::selectOne("SELECT current_database() AS base, current_user AS rol,
            host(inet_server_addr()) AS host, current_setting('search_path') AS search_path");
        $this->assertSame('clinica_vida_salud_test', $identity->base);
        $this->assertSame('clinica_vida_salud_test', $identity->rol);
        $this->assertContains($identity->host, ['127.0.0.1', '::1']);
        $this->assertNotNull(DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'btree_gist'"));
        $namespaces = DB::select('SELECT nspname, nspowner FROM pg_namespace ORDER BY nspname');
        $path = $identity->search_path;
        $this->assertSame(0, DB::transactionLevel());
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function () use ($namespaces, $path): void {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->assertEquals($namespaces, DB::select('SELECT nspname, nspowner FROM pg_namespace ORDER BY nspname'));
            $this->assertSame($path, DB::selectOne('SHOW search_path')->search_path);
            Mail::assertNothingSent();
            Mail::assertNothingQueued();
        });
        $schema = 'prueba_est2_'.strtolower(Str::random(20));
        $this->assertNull(DB::selectOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$schema]));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        $owner = DB::selectOne('SELECT pg_get_userbyid(nspowner) AS propietario FROM pg_namespace WHERE nspname = ?', [$schema]);
        $this->assertSame($identity->rol, $owner->propietario);
        DB::select('SELECT set_config(?, ?, true)', ['search_path', $schema.', public, pg_temp']);
        $this->assertSame($schema, DB::selectOne('SELECT current_schema() AS esquema')->esquema);
        if ($evidencia = getenv('EST2_EVIDENCE_DIR')) {
            file_put_contents($evidencia.'/aislamiento.jsonl', json_encode(['test' => $this->name(), 'identidad' => $identity,
                'esquema_nuevo' => $schema, 'propietario' => $owner->propietario, 'search_path' => DB::selectOne('SHOW search_path')->search_path]).PHP_EOL, FILE_APPEND);
            foreach (['framework/cache', 'framework/views', 'framework/sessions', 'logs'] as $dir) {
                if (! is_dir($evidencia.'/storage/'.$dir)) {
                    mkdir($evidencia.'/storage/'.$dir, 0777, true);
                }
            }
            $this->app->useStoragePath($evidencia.'/storage');
            config(['logging.channels.single.path' => $evidencia.'/storage/logs/laravel.log',
                'view.compiled' => $evidencia.'/storage/framework/views']);
        }
        foreach (glob(base_path('src/data/database/migrations/*.php')) as $file) {
            // Autorización EST-2: persistencia asistencial existente, nunca Pagos/recibos.
            if (preg_match('/_00004[0-2]_/', basename($file))) {
                continue;
            }
            (require $file)->up();
        }
        DB::statement('ALTER FUNCTION bitacora_validar_evento_anterior() SET search_path = "'.$schema.'", public, pg_temp');
        config(['session.driver' => 'database', 'session.lottery' => [0, 100], 'seguridad.correo.mailer' => 'array']);
        $this->app['env'] = 'local';
        Mail::fake();
    }
}
