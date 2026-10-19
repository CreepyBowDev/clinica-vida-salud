<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreferenciaPaciente extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = 'actualizado_en';

    protected $table = 'preferencias_paciente';

    protected $primaryKey = 'id_preferencia';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'recordatorios_habilitados' => 'boolean',
            'preferencias_privacidad' => 'object',
            'actualizado_en' => 'immutable_datetime',
        ];
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'id_paciente', 'id_paciente');
    }
}
