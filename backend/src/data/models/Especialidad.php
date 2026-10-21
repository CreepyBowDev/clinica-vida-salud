<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Especialidad extends Model
{
    protected $table = 'especialidades';

    protected $primaryKey = 'id_especialidad';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['nombre', 'descripcion'];

    protected $attributes = ['activa' => true];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'actualizado_en' => 'immutable_datetime'];
    }

    public function medicos(): BelongsToMany
    {
        return $this->belongsToMany(Medico::class, 'medico_especialidad', 'id_especialidad', 'id_medico', 'id_especialidad', 'id_medico')
            ->using(MedicoEspecialidad::class)->withPivot('habilitada');
    }

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'id_especialidad', 'id_especialidad');
    }
}
