<?php

namespace Clinica\business\services\pacientes;

use Carbon\CarbonImmutable;
use Clinica\business\services\usuarios\AutenticacionService;
use Clinica\business\validators\CodigoActivacionValidator;
use Clinica\data\repositories\bitacora\BitacoraRepository;
use Clinica\data\repositories\pacientes\CodigoActivacionRepository;
use Clinica\data\repositories\usuarios\RegistroPacienteRepository;
use Clinica\shared\errors\AppError;
use Clinica\shared\utils\RegistroPacienteContexto;
use Illuminate\Support\Facades\DB;

class CodigoActivacionService
{
    public function __construct(
        private AutenticacionService $autenticacion,
        private CodigoActivacionRepository $codigos,
        private RegistroPacienteRepository $registros,
        private BitacoraRepository $bitacora,
    ) {}

    public function emitir(int|string $id, bool $identidadVerificada, array $contexto): array
    {
        return DB::transaction(function () use ($id, $identidadVerificada, $contexto): array {
            // Mismo protocolo que Nuevo/Vinculacion: global antes de cualquier ficha o reserva.
            $this->registros->coordinar();
            $actor = $this->autenticacion->exigirSesionNormal(bloquear: true);
            if ($actor->rol->nombre !== 'Recepcionista') {
                throw new AppError('ACCESO_DENEGADO', 'No tienes permiso para realizar esta operación.');
            }
            $paciente = $this->codigos->pacientePorId($id);
            if (! $paciente) {
                throw new AppError('RECURSO_NO_ENCONTRADO', 'No se encontró la ficha solicitada.');
            }
            if (! $identidadVerificada || $paciente->id_usuario !== null
                || ! CodigoActivacionValidator::documentoValido($paciente->documento_identidad)) {
                throw new AppError('ACTIVACION_NO_PERMITIDA', 'Comprueba presencialmente la identidad, el CI y las condiciones de vinculación de la ficha.');
            }
            $ahora = CarbonImmutable::now('UTC');
            foreach ($this->registros->porPaciente($id) as $reserva) {
                $this->registros->invalidar($reserva, $ahora);
            }
            $this->codigos->invalidarAnteriores($id, $ahora);
            // Selección sin sesgo sobre 62 símbolos; nunca se persiste el secreto entregado.
            $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            $valor = '';
            for ($i = 0; $i < 12; $i++) {
                $valor .= $alfabeto[random_int(0, 61)];
            }
            $codigo = $this->codigos->crear(['id_paciente' => $id, 'id_recepcionista' => $actor->getKey(),
                'codigo_hash' => RegistroPacienteContexto::recepcion($id, $valor),
                'emitido_en' => $ahora, 'expira_en' => $ahora->addHours(24)]);
            $this->bitacora->autenticacion('ACTIVACION_PACIENTE_EMITIDA', 'EXITO', $contexto, $actor,
                cambios: ['id_paciente' => (string) $id, 'id_codigo_activacion' => (string) $codigo->getKey()]);

            return ['id_paciente' => (string) $id, 'codigo_activacion' => $valor, 'expira_en' => $codigo->expira_en->toISOString()];
        });
    }
}
