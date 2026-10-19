<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Cita extends Model
{
    public const CREATED_AT = 'creada_en';

    public const UPDATED_AT = 'actualizada_en';

    protected $table = 'citas';

    protected $primaryKey = 'id_cita';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['motivo_breve'];

    protected $attributes = ['estado' => 'Programada', 'modalidad' => 'Presencial', 'version_programacion' => 1];

    protected function casts(): array
    {
        return [
            'inicio_at' => 'immutable_datetime',
            'fin_at' => 'immutable_datetime',
            'duracion_minutos' => 'integer',
            'version_programacion' => 'integer',
            'creada_en' => 'immutable_datetime',
            'actualizada_en' => 'immutable_datetime',
        ];
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'id_paciente', 'id_paciente');
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'id_medico', 'id_medico');
    }

    public function especialidad(): BelongsTo
    {
        return $this->belongsTo(Especialidad::class, 'id_especialidad', 'id_especialidad');
    }

    public function consultorio(): BelongsTo
    {
        return $this->belongsTo(Consultorio::class, 'id_consultorio', 'id_consultorio');
    }

    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creada_por', 'id_usuario');
    }

    public function cambios(): HasMany
    {
        return $this->hasMany(CambioCita::class, 'id_cita', 'id_cita');
    }

    public function recordatorios(): HasMany
    {
        return $this->hasMany(Recordatorio::class, 'id_cita', 'id_cita');
    }

    public function consulta(): HasOne
    {
        return $this->hasOne(ConsultaMedica::class, 'id_cita', 'id_cita');
    }
}
