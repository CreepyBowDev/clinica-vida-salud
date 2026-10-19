<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SolicitudLaboratorio extends Model
{
    protected $table = 'solicitudes_laboratorio';

    protected $primaryKey = 'id_solicitud';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['estudio_solicitado'];

    protected $attributes = ['estado_interno' => 'Pendiente'];

    protected function casts(): array
    {
        return ['solicitada_en' => 'immutable_datetime'];
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(ConsultaMedica::class, 'id_consulta', 'id_consulta');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(VersionResultado::class, 'id_solicitud', 'id_solicitud');
    }

    public function versionActual(): BelongsTo
    {
        return $this->belongsTo(VersionResultado::class, 'id_version_actual', 'id_version_resultado');
    }

    public function revisionPublicada(): BelongsTo
    {
        return $this->belongsTo(RevisionResultado::class, 'id_revision_publicada', 'id_revision');
    }
}
