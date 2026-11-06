<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CobroPago extends Model
{
    protected $table = 'cobros_pago';

    protected $primaryKey = 'id_cobro';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $visible = ['id_cobro'];

    protected function casts(): array
    {
        return ['centavos_usd' => 'integer', 'aplicado' => 'boolean', 'confirmado_en' => 'immutable_datetime',
            'registrado_en' => 'immutable_datetime'];
    }

    public function obligacion(): BelongsTo
    {
        return $this->belongsTo(ObligacionPago::class, 'id_obligacion', 'id_obligacion');
    }

    public function intento(): BelongsTo
    {
        return $this->belongsTo(IntentoPago::class, 'id_intento', 'id_intento');
    }

    public function devolucion(): HasOne
    {
        return $this->hasOne(DevolucionPago::class, 'id_cobro', 'id_cobro');
    }

    public function recibo(): HasOne
    {
        return $this->hasOne(ReciboAcademicoPago::class, 'id_cobro', 'id_cobro');
    }
}
