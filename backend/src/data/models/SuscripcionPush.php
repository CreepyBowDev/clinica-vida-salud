<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuscripcionPush extends Model
{
    protected $table = 'suscripciones_push';

    protected $primaryKey = 'id_suscripcion';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['datos_cifrados', 'instalacion_hash', 'endpoint_hash', 'huella_cuenta'];

    protected function casts(): array
    {
        return ['datos_cifrados' => 'encrypted:array', 'version_autorizacion' => 'integer',
            'autorizada_en' => 'immutable_datetime', 'actualizada_en' => 'immutable_datetime',
            'revocada_en' => 'immutable_datetime', 'expira_en' => 'immutable_datetime'];
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
