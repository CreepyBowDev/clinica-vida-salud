<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VersionAntecedente extends Model
{
    protected $table = 'versiones_antecedente';

    protected $primaryKey = 'id_version_antecedente';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['motivo_cambio', 'tipo_sangre', 'alergias', 'enfermedades_cronicas', 'medicamentos', 'enfermedades_familiares', 'antecedentes_relevantes'];

    protected function casts(): array
    {
        return ['numero_version' => 'integer', 'registrada_en' => 'immutable_datetime'];
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'id_paciente', 'id_paciente');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_autor', 'id_usuario');
    }

    public function consultaOrigen(): BelongsTo
    {
        return $this->belongsTo(ConsultaMedica::class, 'id_consulta_origen', 'id_consulta');
    }
}
