<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DevolucionPago extends Model
{
    protected $table = 'devoluciones_pago';

    protected $primaryKey = 'id_devolucion';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $visible = ['id_devolucion'];

    protected function casts(): array
    {
        return ['centavos_usd' => 'integer', 'cobro_aplicado' => 'boolean', 'creada_en' => 'immutable_datetime',
            'actualizada_en' => 'immutable_datetime'];
    }

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(CobroPago::class, 'id_cobro', 'id_cobro');
    }

    public function cancelacion(): BelongsTo
    {
        return $this->belongsTo(CambioCita::class, 'id_cambio_cancelacion', 'id_cambio_cita');
    }

    public function operaciones(): HasMany
    {
        return $this->hasMany(OperacionPago::class, 'id_devolucion', 'id_devolucion');
    }
}
