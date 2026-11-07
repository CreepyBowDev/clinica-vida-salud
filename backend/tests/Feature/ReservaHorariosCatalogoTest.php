<?php

namespace Tests\Feature;

use Clinica\data\models\Medico;
use Clinica\data\repositories\citas\DisponibilidadRepository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\PostgresConnection;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Eloquent y SQL generado reales; resultados SQL simulados, sin kernel/PDO ni escrituras.
class ReservaHorariosCatalogoTest extends TestCase
{
    public static function horarios(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('horarios')]
    public function test_catalog_projects_only_weekly_intervals_with_one_eager_query(bool $conHorarios): void
    {
        $anterior = Medico::getConnectionResolver();
        $conexion = Mockery::mock(PostgresConnection::class)->makePartial();
        $conexion->__construct(null, 'simulada', '', []);
        $consultas = [];
        $conexion->shouldReceive('select')->andReturnUsing(function ($sql, $bindings) use (&$consultas, $conHorarios): array {
            $consultas[] = $sql;
            if (str_contains($sql, 'count(*) as "aggregate"')) {
                return [(object) ['aggregate' => 2]];
            }
            if (str_contains($sql, 'from "medicos"')) {
                $this->assertStringContainsString('exists (select * from "horarios_atencion"', $sql);

                return array_map(fn ($id) => (object) ['id_medico' => $id, 'id_consultorio' => '3',
                    'codigo_medico' => 'MED-'.$id, 'nombres' => 'Nombre', 'apellidos' => 'Ficticio'], ['101', '102']);
            }
            if (str_contains($sql, 'from "consultorios"')) {
                return [(object) ['id_consultorio' => '3', 'codigo_consultorio' => 'C-3', 'nombre' => 'Sala ficticia']];
            }
            $this->assertStringContainsString('from "horarios_atencion"', $sql);
            $this->assertStringContainsString('"activo" = ?', $sql);
            $this->assertContains(true, $bindings);
            $this->assertStringContainsString('"horarios_atencion"."id_medico" in (101, 102)', $sql);
            $this->assertStringContainsString('order by "dia_semana" asc, "hora_inicio" asc, "hora_fin" asc', $sql);

            return $conHorarios ? [(object) ['id_medico' => '101', 'dia_semana' => 1, 'hora_inicio' => '08:00:01', 'hora_fin' => '12:00:01'],
                (object) ['id_medico' => '101', 'dia_semana' => 1, 'hora_inicio' => '14:00:00', 'hora_fin' => '18:00:00'],
                (object) ['id_medico' => '102', 'dia_semana' => 2, 'hora_inicio' => '10:00:00', 'hora_fin' => '11:00:00']] : [];
        });
        $resolver = new ConnectionResolver(['simulada' => $conexion]);
        $resolver->setDefaultConnection('simulada');
        Medico::setConnectionResolver($resolver);
        try {
            $pagina = (new DisponibilidadRepository)->medicos(['id_especialidad' => '1', 'per_page' => 10, 'page' => 1]);
            $datos = $pagina->items();
            $this->assertSame(2, $pagina->total());
            $this->assertSame(['id_medico', 'codigo_medico', 'nombres', 'apellidos', 'horarios', 'consultorio'], array_keys($datos[0]));
            $this->assertSame($conHorarios ? [
                ['dia_semana' => 1, 'hora_inicio' => '08:00:01', 'hora_fin' => '12:00:01'],
                ['dia_semana' => 1, 'hora_inicio' => '14:00:00', 'hora_fin' => '18:00:00'],
            ] : [], $datos[0]['horarios']);
            $this->assertSame($conHorarios ? [
                ['dia_semana' => 2, 'hora_inicio' => '10:00:00', 'hora_fin' => '11:00:00'],
            ] : [], $datos[1]['horarios']);
            $this->assertCount(4, $consultas); // Count + página + consultorios + horarios; no N+1.
        } finally {
            if ($anterior === null) {
                Medico::unsetConnectionResolver();
            } else {
                Medico::setConnectionResolver($anterior);
            }
            Mockery::close();
        }
    }
}
