<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsultaMedica extends Model
{
    public const CREATED_AT = 'creada_en';

    public const UPDATED_AT = null;

    protected $table = 'consultas_medicas';

    protected $primaryKey = 'id_consulta';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['iniciada_en' => 'immutable_datetime', 'creada_en' => 'immutable_datetime'];
    }

    public function cita(): BelongsTo
    {
        return $this->belongsTo(Cita::class, 'id_cita', 'id_cita');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(VersionConsulta::class, 'id_consulta', 'id_consulta');
    }

    public function versionesAntecedentesOrigen(): HasMany
    {
        return $this->hasMany(VersionAntecedente::class, 'id_consulta_origen', 'id_consulta');
    }

    public function solicitudesLaboratorio(): HasMany
    {
        return $this->hasMany(SolicitudLaboratorio::class, 'id_consulta', 'id_consulta');
    }
}
