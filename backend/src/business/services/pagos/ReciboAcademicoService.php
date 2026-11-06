<?php

namespace Clinica\business\services\pagos;

use Clinica\data\models\CobroPago;
use Clinica\data\models\IntentoPago;
use Clinica\data\models\ObligacionPago;
use Clinica\data\repositories\pagos\ReciboAcademicoRepository;
use Clinica\shared\errors\AppError;
use Illuminate\Support\Facades\DB;

class ReciboAcademicoService
{
    public function __construct(private ReciboAcademicoRepository $repo, private ConsultaPagosService $consulta) {}

    /** Únicamente dentro de la transacción del cobro; nunca desde GET/retorno/formulario. */
    public function emitir(ObligacionPago $o, IntentoPago $i, CobroPago $c): void
    {
        if (DB::transactionLevel() === 0 || ! $c->aplicado || $c->proveedor !== 'simulado'
            || $i->origen !== 'Simulado' || (string) $c->id_intento !== (string) $i->getKey()
            || (string) $c->id_obligacion !== (string) $o->getKey()) {
            throw new \LogicException('El recibo requiere un cobro académico aplicado en transacción.');
        }
        $p = $this->repo->titular((string) $o->cita->id_paciente);
        $this->repo->crear(['id_cobro' => $c->getKey(), 'numero' => 'REC-ACA-'.$c->getKey(),
            'id_obligacion' => $o->getKey(), 'centavos_bob' => $o->centavos_bob, 'centavos_usd' => $o->centavos_usd,
            'tasa_numerador' => $o->tasa_numerador, 'tasa_denominador' => $o->tasa_denominador,
            'id_paciente' => $p->getKey(), 'codigo_paciente' => $p->codigo_paciente,
            'nombre_titular' => trim($p->nombres.' '.$p->apellidos), 'codigo_cita' => $o->cita->codigo_cita,
            'modalidad' => $i->modalidad, 'pagado_en' => $c->confirmado_en, 'emitido_en' => $c->confirmado_en]);
    }

    public function consultar(array $filtros): \Clinica\data\models\ReciboAcademicoPago
    {
        $autorizada = $this->consulta->consultar('detalle', $filtros);

        return $this->repo->porObligacion((string) $autorizada['obligacion']->getKey())
            ?? throw new AppError('RECURSO_NO_ENCONTRADO', 'No hay recibo académico disponible para esta obligación.');
    }
}
