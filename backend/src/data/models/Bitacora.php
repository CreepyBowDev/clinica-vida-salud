<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bitacora extends Model
{
    protected $table = 'bitacora';

    protected $primaryKey = 'id_evento';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'ocurrido_en' => 'immutable_datetime',
            'registrado_en' => 'immutable_datetime',
            'version_formato' => 'integer',
            'cambios_seguros' => 'object',
            'metadatos_seguros' => 'object',
            'referencias' => 'object',
        ];
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_responsable', 'id_usuario');
    }

    public function objetivo(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario_objetivo', 'id_usuario');
    }

    public function eventoRelacionado(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id_evento_relacionado', 'id_evento');
    }

    public function eventosPosteriores(): HasMany
    {
        return $this->hasMany(self::class, 'id_evento_relacionado', 'id_evento');
    }
}
