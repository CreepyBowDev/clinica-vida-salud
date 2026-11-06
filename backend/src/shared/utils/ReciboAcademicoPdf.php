<?php

namespace Clinica\shared\utils;

use Clinica\data\models\ReciboAcademicoPago;
use Dompdf\Dompdf;
use Dompdf\Options;

class ReciboAcademicoPdf
{
    public const AVISO = 'Recibo académico de pago simulado. No constituye factura fiscal ni acredita una transferencia real';

    public function generar(ReciboAcademicoPago $r): string
    {
        $precio = ImportePago::desdeHistorico($r->centavos_bob, $r->centavos_usd, $r->tasa_numerador, $r->tasa_denominador)->precio();
        $filas = ['Número' => $r->numero, 'Cita' => $r->codigo_cita,
            'Paciente titular' => $r->nombre_titular, 'Código de paciente' => $r->codigo_paciente,
            'Fecha del pago (America/La_Paz)' => $r->pagado_en->setTimezone('America/La_Paz')->format('d/m/Y H:i:s P'),
            'Importe histórico' => 'BOB '.$precio['monto_bob'], 'Equivalente académico' => 'USD '.$precio['monto_usd'],
            'Tasa histórica' => $precio['tasa_bob_por_usd'].' BOB por 1 USD',
            'Modalidad representada' => $r->modalidad === 'paypal' ? 'PayPal' : 'Stripe',
            'Origen' => 'Académico simulado, sin transferencia de dinero'];
        $html = '<!doctype html><html lang="es"><head><meta charset="UTF-8"><style>'
            .'body{font-family:"DejaVu Sans",sans-serif;font-size:11pt;color:#18333b}h1{font-size:20pt}'
            .'th,td{text-align:left;padding:8px;border-bottom:1px solid #d5e0e5;overflow-wrap:break-word}th{width:36%}'
            .'</style></head><body><h1>Recibo académico</h1><p>Clínica Vida Salud — proyecto universitario</p><table>';
        foreach ($filas as $nombre => $valor) {
            $html .= '<tr><th>'.htmlspecialchars($nombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</th><td>'
                .htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</td></tr>';
        }
        $html .= '</table><p>'.self::AVISO.'</p><p>Identidad conservada al registrar el pago. '
            .'Este recibo original no se sustituye por una cancelación o devolución posterior; consulta su situación por separado en el portal.</p></body></html>';
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setTempDir(storage_path('framework/cache'));
        $options->setFontCache(storage_path('framework/cache'));
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }
}
