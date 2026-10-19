<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VersionResultado extends Model
{
    protected $table = 'versiones_resultado';

    protected $primaryKey = 'id_version_resultado';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['motivo_cambio'];

    protected function casts(): array
    {
        return ['numero_version' => 'integer', 'registrada_en' => 'immutable_datetime'];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudLaboratorio::class, 'id_solicitud', 'id_solicitud');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoLaboratorio::class, 'id_documento', 'id_documento');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id_version_origen', 'id_version_resultado');
    }

    public function derivadas(): HasMany
    {
        return $this->hasMany(self::class, 'id_version_origen', 'id_version_resultado');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_autor', 'id_usuario');
    }

    public function revisiones(): HasMany
    {
        return $this->hasMany(RevisionResultado::class, 'id_version_resultado', 'id_version_resultado');
    }

    public function solicitudActual(): HasOne
    {
        return $this->hasOne(SolicitudLaboratorio::class, 'id_version_actual', 'id_version_resultado');
    }
}
