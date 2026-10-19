<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificacionCorreo extends Model
{
    protected $table = 'verificaciones_correo';

    protected $primaryKey = 'id_verificacion';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['codigo_hash', 'intentos_fallidos'];

    protected $attributes = ['intentos_fallidos' => 0];

    protected function casts(): array
    {
        return [
            'enviado_en' => 'immutable_datetime',
            'expira_en' => 'immutable_datetime',
            'intentos_fallidos' => 'integer',
            'consumido_en' => 'immutable_datetime',
            'invalidado_en' => 'immutable_datetime',
        ];
    }

    protected function correoDestino(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value, ' '), 'UTF-8'));
    }

    public function registroPendiente(): BelongsTo
    {
        return $this->belongsTo(RegistroPacientePendiente::class, 'id_registro_pendiente', 'id_registro_pendiente');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }
}
