<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;

class ReciboAcademicoPago extends Model
{
    protected $table = 'recibos_academicos_pago';
    protected $primaryKey = 'id_cobro';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.uP';
    protected $guarded = ['*'];
    protected $visible = ['numero'];

    protected function casts(): array
    {
        return ['centavos_bob' => 'integer', 'centavos_usd' => 'integer', 'tasa_numerador' => 'integer',
            'tasa_denominador' => 'integer', 'pagado_en' => 'immutable_datetime', 'emitido_en' => 'immutable_datetime'];
    }
}
