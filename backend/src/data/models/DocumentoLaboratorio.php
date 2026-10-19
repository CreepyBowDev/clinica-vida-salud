<?php

namespace Clinica\data\models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentoLaboratorio extends Model
{
    protected $table = 'documentos_laboratorio';

    protected $primaryKey = 'id_documento';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = ['*'];

    protected $hidden = ['ruta_privada', 'nombre_original', 'sha256'];

    protected function casts(): array
    {
        return ['tamano_bytes' => 'integer', 'cargado_en' => 'immutable_datetime'];
    }

    public function cargadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cargado_por', 'id_usuario');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(VersionResultado::class, 'id_documento', 'id_documento');
    }
}
