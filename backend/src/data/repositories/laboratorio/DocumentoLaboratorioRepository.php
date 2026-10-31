<?php

namespace Clinica\data\repositories\laboratorio;

use Carbon\CarbonImmutable;
use Clinica\data\models\DocumentoLaboratorio;

class DocumentoLaboratorioRepository
{
    public function crear(string $ruta, array $metadatos, string $autor, CarbonImmutable $ahora): DocumentoLaboratorio
    {
        $doc = new DocumentoLaboratorio;
        unset($metadatos['extension']);
        $doc->forceFill([...$metadatos, 'ruta_privada' => $ruta, 'cargado_por' => $autor, 'cargado_en' => $ahora])->saveOrFail();

        return $doc;
    }

    public function porId(string $id): ?DocumentoLaboratorio
    {
        return DocumentoLaboratorio::query()->whereKey($id)->first();
    }

    public function rutaReferenciada(string $ruta): bool
    {
        return DocumentoLaboratorio::query()->where('ruta_privada', $ruta)->exists();
    }
}
