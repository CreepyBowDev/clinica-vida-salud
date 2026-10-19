<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Usuario extends Authenticatable
{
    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $table = 'usuarios';

    protected $primaryKey = 'id_usuario';

    protected $authPasswordName = 'password_hash';

    protected $rememberTokenName = '';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['correo_acceso', 'nombres_personal', 'apellidos_personal'];

    protected $hidden = ['password_hash', 'intentos_fallidos', 'bloqueado_hasta'];

    protected $attributes = [
        'estado_cuenta' => 'Pendiente',
        'cambio_clave_obligatorio' => false,
        'intentos_fallidos' => 0,
    ];

    protected function casts(): array
    {
        return [
            'correo_verificado_en' => 'immutable_datetime',
            'cambio_clave_obligatorio' => 'boolean',
            'intentos_fallidos' => 'integer',
            'bloqueado_hasta' => 'immutable_datetime',
            'creado_en' => 'immutable_datetime',
            'actualizado_en' => 'immutable_datetime',
        ];
    }

    protected function correoAcceso(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value, ' '), 'UTF-8'));
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'id_rol', 'id_rol');
    }

    public function tokensAcceso(): HasMany
    {
        return $this->hasMany(TokenAcceso::class, 'id_usuario', 'id_usuario');
    }

    public function paciente(): HasOne
    {
        return $this->hasOne(Paciente::class, 'id_usuario', 'id_usuario');
    }

    public function medico(): HasOne
    {
        return $this->hasOne(Medico::class, 'id_usuario', 'id_usuario');
    }

    public function codigosActivacionEmitidos(): HasMany
    {
        return $this->hasMany(CodigoActivacionPaciente::class, 'id_recepcionista', 'id_usuario');
    }

    public function verificacionesCorreo(): HasMany
    {
        return $this->hasMany(VerificacionCorreo::class, 'id_usuario', 'id_usuario');
    }

    public function eventosComoResponsable(): HasMany
    {
        return $this->hasMany(Bitacora::class, 'id_usuario_responsable', 'id_usuario');
    }

    public function eventosComoObjetivo(): HasMany
    {
        return $this->hasMany(Bitacora::class, 'id_usuario_objetivo', 'id_usuario');
    }

    public function citasCreadas(): HasMany
    {
        return $this->hasMany(Cita::class, 'creada_por', 'id_usuario');
    }

    public function cambiosCita(): HasMany
    {
        return $this->hasMany(CambioCita::class, 'id_actor', 'id_usuario');
    }

    public function versionesConsultas(): HasMany
    {
        return $this->hasMany(VersionConsulta::class, 'id_autor', 'id_usuario');
    }

    public function versionesAntecedentes(): HasMany
    {
        return $this->hasMany(VersionAntecedente::class, 'id_autor', 'id_usuario');
    }

    public function documentosLaboratorioCargados(): HasMany
    {
        return $this->hasMany(DocumentoLaboratorio::class, 'cargado_por', 'id_usuario');
    }

    public function versionesResultados(): HasMany
    {
        return $this->hasMany(VersionResultado::class, 'id_autor', 'id_usuario');
    }
}
