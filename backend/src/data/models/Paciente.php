<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Paciente extends Model
{
    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $table = 'pacientes';

    protected $primaryKey = 'id_paciente';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['documento_identidad', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'correo_contacto', 'direccion'];

    protected $attributes = ['activo' => true];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'immutable_date:Y-m-d',
            'activo' => 'boolean',
            'creado_en' => 'immutable_datetime',
            'actualizado_en' => 'immutable_datetime',
        ];
    }

    protected function documentoIdentidad(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : trim($value, ' '));
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    public function contactoEmergencia(): HasOne
    {
        return $this->hasOne(ContactoEmergencia::class, 'id_paciente', 'id_paciente');
    }

    public function preferencia(): HasOne
    {
        return $this->hasOne(PreferenciaPaciente::class, 'id_paciente', 'id_paciente');
    }

    public function codigosActivacion(): HasMany
    {
        return $this->hasMany(CodigoActivacionPaciente::class, 'id_paciente', 'id_paciente');
    }

    public function registrosPendientes(): HasMany
    {
        return $this->hasMany(RegistroPacientePendiente::class, 'id_paciente', 'id_paciente');
    }

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'id_paciente', 'id_paciente');
    }

    public function versionesAntecedentes(): HasMany
    {
        return $this->hasMany(VersionAntecedente::class, 'id_paciente', 'id_paciente');
    }
}
