<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CambioCita extends Model
{
    protected $table = 'cambios_cita';

    protected $primaryKey = 'id_cambio_cita';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['datos_anteriores', 'datos_nuevos', 'motivo'];

    protected function casts(): array
    {
        return [
            'realizado_en' => 'immutable_datetime',
            'datos_anteriores' => 'object',
            'datos_nuevos' => 'object',
        ];
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class, 'id_cita', 'id_cita');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_actor', 'id_usuario');
    }
}
