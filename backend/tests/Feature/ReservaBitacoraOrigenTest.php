<?php

namespace Tests\Feature;

use Clinica\data\models\Bitacora;
use Clinica\data\models\Cita;
use Clinica\data\models\Rol;
use Clinica\data\models\Usuario;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\presentation\dtos\bitacora\BitacoraDetalleResource;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservaBitacoraOrigenTest extends TestCase
{
    public static function origenes(): array
    {
        return [['Administrador', 'ADMINISTRACION'], ['Recepcionista', 'RECEPCION'], ['Paciente', 'PROPIA']];
    }

    #[DataProvider('origenes')]
    public function test_real_audit_repository_and_resource_keep_executor_and_safe_origin_without_sql(string $rol, string $origen): void
    {
        // Se captura el evento real antes de persistir; conexión sin PDO e INSERT prohibido.
        $connection = Mockery::mock(Connection::class)->makePartial();
        $connection->__construct(null);
        $connection->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $connection->shouldReceive('getPdo')->never();
        $connection->shouldReceive('insert')->never();
        DB::shouldReceive('connection')->andReturn($connection);
        $capturado = null;
        Bitacora::saving(function (Bitacora $evento) use (&$capturado) {
            $capturado = clone $evento;

            return false;
        });
        $actor = (new Usuario)->forceFill(['id_usuario' => 71]);
        $actor->setRelation('rol', (new Rol)->forceFill(['nombre' => $rol]));
        $cita = (new Cita)->forceFill(['id_cita' => 81, 'id_paciente' => 25, 'id_medico' => 31,
            'id_especialidad' => 51, 'id_consultorio' => 41, 'duracion_minutos' => 20, 'motivo_breve' => 'Contenido privado ficticio']);
        $this->app->make(BitacoraRepository::class)->citaProgramada($cita, $actor,
            ['ip' => '192.0.2.1', 'procedencia' => 'DIRECTA', 'correlacion' => '00000000-0000-4000-8000-000000000001']);
        $this->assertInstanceOf(Bitacora::class, $capturado);
        $this->assertSame(71, $capturado->id_usuario_responsable);
        $this->assertSame($rol, $capturado->rol_responsable);
        $this->assertSame('25', $capturado->referencias->id_paciente);
        $this->assertSame('31', $capturado->referencias->id_medico);
        $this->assertSame($origen, $capturado->cambios_seguros->origen);
        $capturado->id_evento = 9;
        $capturado->registrado_en = $capturado->ocurrido_en;
        $capturado->version_formato = 1;
        $salida = (new BitacoraDetalleResource($capturado))->toArray(Request::create('/api/bitacora/9'));
        $this->assertSame($origen, $salida['cambios_seguros']->origen);
        $this->assertStringNotContainsString('Contenido privado ficticio', json_encode($salida));
        $this->assertFalse($capturado->exists);
    }
}
