<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvioRecordatorioPush extends Model
{
    protected $table = 'envios_recordatorio_push';

    protected $primaryKey = 'id_envio';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['codigo_reclamo'];

    protected function casts(): array
    {
        return ['version_autorizacion' => 'integer', 'intentos' => 'integer', 'proximo_intento_en' => 'immutable_datetime',
            'reclamado_hasta' => 'immutable_datetime', 'creado_en' => 'immutable_datetime', 'actualizado_en' => 'immutable_datetime'];
    }

    public function recordatorio(): BelongsTo
    {
        return $this->belongsTo(Recordatorio::class, 'id_recordatorio', 'id_recordatorio');
    }

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(SuscripcionPush::class, 'id_suscripcion', 'id_suscripcion');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'id_paciente', 'id_paciente');
    }
}
