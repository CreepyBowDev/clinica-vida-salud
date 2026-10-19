<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorarioAtencion extends Model
{
    protected $table = 'horarios_atencion';

    protected $primaryKey = 'id_horario';

    public $timestamps = false;

    protected $fillable = ['dia_semana', 'hora_inicio', 'hora_fin'];

    protected $attributes = ['activo' => true];

    protected function casts(): array
    {
        return ['dia_semana' => 'integer', 'activo' => 'boolean'];
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'id_medico', 'id_medico');
    }
}
