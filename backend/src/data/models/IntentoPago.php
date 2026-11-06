<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntentoPago extends Model
{
    protected $table = 'intentos_pago';

    protected $primaryKey = 'id_intento';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $visible = ['id_intento'];

    protected function casts(): array
    {
        return ['aprobada_en' => 'immutable_datetime', 'captura_permitida' => 'boolean', 'creado_en' => 'immutable_datetime', 'actualizado_en' => 'immutable_datetime',
            'proxima_revision_en' => 'immutable_datetime', 'revisado_en' => 'immutable_datetime'];
    }

    public function obligacion(): BelongsTo
    {
        return $this->belongsTo(ObligacionPago::class, 'id_obligacion', 'id_obligacion');
    }

    public function iniciador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'iniciado_por', 'id_usuario');
    }

    public function cobros(): HasMany
    {
        return $this->hasMany(CobroPago::class, 'id_intento', 'id_intento');
    }

    public function operaciones(): HasMany
    {
        return $this->hasMany(OperacionPago::class, 'id_intento', 'id_intento');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoPago::class, 'id_intento', 'id_intento');
    }
}
