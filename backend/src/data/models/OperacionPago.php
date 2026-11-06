<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperacionPago extends Model
{
    protected $table = 'operaciones_pago';

    protected $primaryKey = 'id_operacion';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $visible = ['id_operacion'];

    protected function casts(): array
    {
        return ['datos_solicitud_cifrados' => 'encrypted:array', 'ejecuciones' => 'integer',
            'proxima_ejecucion_en' => 'immutable_datetime', 'reclamado_hasta' => 'immutable_datetime',
            'creada_en' => 'immutable_datetime', 'actualizada_en' => 'immutable_datetime'];
    }

    public function intento(): BelongsTo
    {
        return $this->belongsTo(IntentoPago::class, 'id_intento', 'id_intento');
    }

    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(DevolucionPago::class, 'id_devolucion', 'id_devolucion');
    }

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(CobroPago::class, 'id_cobro', 'id_cobro');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitada_por', 'id_usuario');
    }
}
