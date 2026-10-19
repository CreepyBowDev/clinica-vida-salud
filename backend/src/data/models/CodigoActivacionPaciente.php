<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CodigoActivacionPaciente extends Model
{
    protected $table = 'codigos_activacion_paciente';

    protected $primaryKey = 'id_codigo_activacion';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['codigo_hash', 'intentos_fallidos'];

    protected $attributes = ['intentos_fallidos' => 0];

    protected function casts(): array
    {
        return [
            'emitido_en' => 'immutable_datetime',
            'expira_en' => 'immutable_datetime',
            'intentos_fallidos' => 'integer',
            'consumido_en' => 'immutable_datetime',
            'invalidado_en' => 'immutable_datetime',
        ];
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'id_paciente', 'id_paciente');
    }

    public function recepcionista(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_recepcionista', 'id_usuario');
    }

    public function registrosPendientes(): HasMany
    {
        return $this->hasMany(RegistroPacientePendiente::class, 'id_codigo_activacion', 'id_codigo_activacion');
    }
}
