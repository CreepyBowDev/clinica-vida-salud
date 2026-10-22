<?php

namespace Tests\Fixtures;

use Carbon\CarbonImmutable;
use Clinica\business\validators\IntervalosAtencion;
use Clinica\data\repositories\horarios\PlanificacionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Solo reproduce el protocolo de futuros CU-14/CU-23, sin endpoint ni autorización clínica.
class EscritorPlanificacionSimulado
{
    public static function ejecutar(array $datos): JsonResponse
    {
        try {
            return DB::transaction(function () use ($datos): JsonResponse {
                (new PlanificacionRepository)->coordinar();
                $configuracion = DB::table('configuracion_clinica')->where('id_configuracion', 1)->lockForUpdate()->first();
                $medico = DB::table('medicos')->where('id_medico', $datos['id_medico'])->lockForUpdate()->first();
                DB::table('consultorios')->where('id_consultorio', $medico->id_consultorio)->lockForUpdate()->first();
                if ($datos['tipo'] === 'reserva') {
                    $fila = $datos['fila_cita'];
                    $horarios = DB::table('horarios_atencion')->where('id_medico', $medico->id_medico)->where('activo', true)->get();
                    if (! $medico->activo || (string) $medico->id_consultorio !== (string) $fila['id_consultorio']
                        || $configuracion->duracion_turno_minutos !== $fila['duracion_minutos']
                        || ! (new IntervalosAtencion)->cubierto(CarbonImmutable::parse($fila['inicio_at']), CarbonImmutable::parse($fila['fin_at']), $horarios)) {
                        return new JsonResponse(['error' => ['code' => 'PROTOCOLO_PROPUESTA_OBSOLETA']], 409);
                    }
                    DB::table('citas')->insert($fila);
                } else {
                    DB::table('citas')->where('id_cita', $datos['id_cita'])->lockForUpdate()->first();
                    DB::table('consultas_medicas')->insert(['codigo_consulta' => 'SIMULADA-PROTOCOLO', 'id_cita' => $datos['id_cita'], 'iniciada_en' => null]);
                }
                if ($datos['pausar_protocolo'] ?? false) {
                    echo "PROTOCOLO_PENDIENTE\n";
                    fflush(STDOUT);
                    $decision = trim(fgets(STDIN));
                    if ($decision === 'REVERTIR') {
                        throw new RuntimeException('Fallo simulado del protocolo.');
                    }
                    if ($decision !== 'CONFIRMAR') {
                        throw new RuntimeException('Falta barrera de protocolo.');
                    }
                }

                return new JsonResponse([], 201);
            });
        } catch (RuntimeException) {
            return new JsonResponse(['error' => ['code' => 'PROTOCOLO_REVERTIDO']], 500);
        }
    }
}
