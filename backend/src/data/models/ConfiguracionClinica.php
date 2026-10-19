<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionClinica extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = 'actualizado_en';

    protected $table = 'configuracion_clinica';

    protected $primaryKey = 'id_configuracion';

    public $incrementing = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['nombre_sede', 'direccion_sede', 'duracion_turno_minutos'];

    protected $attributes = [
        'id_configuracion' => 1,
        'zona_horaria' => 'America/La_Paz',
        'duracion_turno_minutos' => 15,
    ];

    protected function casts(): array
    {
        return [
            'duracion_turno_minutos' => 'integer',
            'actualizado_en' => 'immutable_datetime',
        ];
    }
}
