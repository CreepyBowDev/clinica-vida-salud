<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoPago extends Model
{
    protected $table = 'eventos_pago';

    protected $primaryKey = 'id_evento';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $visible = ['id_evento'];

    protected function casts(): array
    {
        return ['referencias' => 'array', 'evidencia_cifrada' => 'encrypted:array', 'ejecuciones' => 'integer',
            'recepcion_en' => 'immutable_datetime', 'procesado_en' => 'immutable_datetime',
            'proxima_ejecucion_en' => 'immutable_datetime', 'reclamado_hasta' => 'immutable_datetime'];
    }

    public function intento(): BelongsTo
    {
        return $this->belongsTo(IntentoPago::class, 'id_intento', 'id_intento');
    }
}
