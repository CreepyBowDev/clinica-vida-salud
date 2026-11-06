<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ObligacionPago extends Model
{
    protected $table = 'obligaciones_pago';

    protected $primaryKey = 'id_obligacion';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    // La futura proyección autorizada debe construir su DTO, nunca devolver Models.
    protected $visible = ['id_obligacion'];

    protected function casts(): array
    {
        return ['centavos_bob' => 'integer', 'centavos_usd' => 'integer', 'tasa_numerador' => 'integer',
            'tasa_denominador' => 'integer', 'incorporada_finanzas' => 'boolean', 'exigible' => 'boolean',
            'emitida_en' => 'immutable_datetime', 'vencimiento_at' => 'immutable_datetime', 'anulada_en' => 'immutable_datetime'];
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class, 'id_cita', 'id_cita');
    }

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'emitida_por', 'id_usuario');
    }

    public function cambioAnulacion(): BelongsTo
    {
        return $this->belongsTo(CambioCita::class, 'id_cambio_anulacion', 'id_cambio_cita');
    }

    public function intentos(): HasMany
    {
        return $this->hasMany(IntentoPago::class, 'id_obligacion', 'id_obligacion');
    }

    public function cobros(): HasMany
    {
        return $this->hasMany(CobroPago::class, 'id_obligacion', 'id_obligacion');
    }

    public function liquidacion(): HasOne
    {
        return $this->hasOne(CobroPago::class, 'id_obligacion', 'id_obligacion')->where('aplicado', true);
    }
}
