<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recordatorio extends Model
{
    protected $table = 'recordatorios';

    protected $primaryKey = 'id_recordatorio';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version_programacion' => 'integer',
            'generado_en' => 'immutable_datetime',
            'leido_en' => 'immutable_datetime',
            'invalidado_en' => 'immutable_datetime',
        ];
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class, 'id_cita', 'id_cita');
    }
}
