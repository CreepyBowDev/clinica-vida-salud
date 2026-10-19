<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VersionConsulta extends Model
{
    protected $table = 'versiones_consulta';

    protected $primaryKey = 'id_version_consulta';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['motivo_cambio', 'tipo_consulta', 'motivo_consulta', 'sintomas', 'observaciones', 'diagnostico', 'indicaciones_tratamiento'];

    protected function casts(): array
    {
        return ['numero_version' => 'integer', 'registrada_en' => 'immutable_datetime'];
    }

    public function consulta(): BelongsTo
    {
        return $this->belongsTo(ConsultaMedica::class, 'id_consulta', 'id_consulta');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_autor', 'id_usuario');
    }
}
