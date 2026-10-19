<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;

class ControlIntentos extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = 'actualizado_en';

    protected $table = 'controles_intentos';

    protected $primaryKey = 'id_control';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['clave_ambito_hash', 'inicio_ventana'];

    protected $hidden = ['clave_ambito_hash'];

    protected $attributes = ['cantidad_intentos' => 0];

    protected function casts(): array
    {
        return [
            'inicio_ventana' => 'immutable_datetime',
            'cantidad_intentos' => 'integer',
            'bloqueado_hasta' => 'immutable_datetime',
            'actualizado_en' => 'immutable_datetime',
        ];
    }
}
