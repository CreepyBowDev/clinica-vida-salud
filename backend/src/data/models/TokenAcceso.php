<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenAcceso extends Model
{
    protected $table = 'tokens_acceso';

    protected $primaryKey = 'id_token';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'emitido_en' => 'immutable_datetime',
            'expira_en' => 'immutable_datetime',
            'consumido_en' => 'immutable_datetime',
            'invalidado_en' => 'immutable_datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }
}
