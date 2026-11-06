<?php

namespace Clinica\presentation\controllers\pagos;

use Clinica\business\services\pagos\ReciboAcademicoService;
use Clinica\presentation\dtos\pagos\ReciboAcademicoRequest;
use Clinica\shared\utils\ReciboAcademicoPdf;
use Symfony\Component\HttpFoundation\Response;

class ReciboAcademicoController
{
    public function descargar(ReciboAcademicoRequest $request, ReciboAcademicoService $service, ReciboAcademicoPdf $pdf): Response
    {
        // Misma autorización de rol/titularidad que la obligación, también para entregar los bytes.
        $recibo = $service->consultar($request->validated());

        return new Response($request->isMethod('HEAD') ? '' : $pdf->generar($recibo), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$recibo->numero.'.pdf"',
            'Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Accept-Ranges' => 'none',
        ]);
    }
}
