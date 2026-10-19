<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medico extends Model
{
    protected $table = 'medicos';

    protected $primaryKey = 'id_medico';

    public $timestamps = false;

    protected $fillable = ['registro_profesional', 'nombres', 'apellidos', 'telefono', 'correo_contacto'];

    protected $attributes = ['activo' => true];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    public function consultorio(): BelongsTo
    {
        return $this->belongsTo(Consultorio::class, 'id_consultorio', 'id_consultorio');
    }

    public function especialidades(): BelongsToMany
    {
        return $this->belongsToMany(Especialidad::class, 'medico_especialidad', 'id_medico', 'id_especialidad', 'id_medico', 'id_especialidad')
            ->using(MedicoEspecialidad::class);
    }

    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioAtencion::class, 'id_medico', 'id_medico');
    }

    public function citas(): HasMany
    {
        return $this->hasMany(Cita::class, 'id_medico', 'id_medico');
    }

    public function revisionesResultados(): HasMany
    {
        return $this->hasMany(RevisionResultado::class, 'id_medico', 'id_medico');
    }
}
