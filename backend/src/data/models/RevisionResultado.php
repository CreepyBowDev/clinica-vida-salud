<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RevisionResultado extends Model
{
    protected $table = 'revisiones_resultado';

    protected $primaryKey = 'id_revision';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['motivo'];

    protected function casts(): array
    {
        return ['revisada_en' => 'immutable_datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(VersionResultado::class, 'id_version_resultado', 'id_version_resultado');
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'id_medico', 'id_medico');
    }

    public function solicitudPublicada(): HasOne
    {
        return $this->hasOne(SolicitudLaboratorio::class, 'id_revision_publicada', 'id_revision');
    }
}
