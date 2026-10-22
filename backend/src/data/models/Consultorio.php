<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consultorio extends Model
{
    protected $table = 'consultorios';

    protected $primaryKey = 'id_consultorio';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['codigo_consultorio', 'nombre'];

    protected $attributes = ['activo' => true];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'actualizado_en' => 'immutable_datetime'];
    }

    public function medicos(): HasMany
    {
        return $this->hasMany(Medico::class, 'id_consultorio', 'id_consultorio');
    }

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'id_consultorio', 'id_consultorio');
    }
}
