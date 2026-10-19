<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegistroPacientePendiente extends Model
{
    protected $table = 'registros_paciente_pendientes';

    protected $primaryKey = 'id_registro_pendiente';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['password_hash_temporal', 'datos_registro'];

    protected function casts(): array
    {
        return [
            'datos_registro' => 'object',
            'iniciado_en' => 'immutable_datetime',
            'expira_en' => 'immutable_datetime',
            'completado_en' => 'immutable_datetime',
            'invalidado_en' => 'immutable_datetime',
        ];
    }

    protected function documentoIdentidad(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : trim($value, ' '));
    }

    protected function correoPropuesto(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value, ' '), 'UTF-8'));
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'id_paciente', 'id_paciente');
    }

    public function codigoActivacion(): BelongsTo
    {
        return $this->belongsTo(CodigoActivacionPaciente::class, 'id_codigo_activacion', 'id_codigo_activacion');
    }

    public function verificacionesCorreo(): HasMany
    {
        return $this->hasMany(VerificacionCorreo::class, 'id_registro_pendiente', 'id_registro_pendiente');
    }
}
