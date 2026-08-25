<?php
/** Descarga el PDF de una cotización con el diseño de su empresa. */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('cotizaciones.gestionar');

$cot = Cotizacion::buscar((int) ($_GET['id'] ?? 0));
if (!$cot) {
    Sesion::flash('error', 'Esa cotización no es de la empresa activa.');
    Vista::redirigir('index.php');
}

$pdf = new CotizacionPdf($cot, CotizacionConfig::actual(), Empresa::ficha());
$bin = $pdf->generar();

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/pdf');
// Se abre en el navegador salvo que se pida bajarlo: lo normal es revisarlo
// antes de mandárselo al cliente.
header('Content-Disposition: ' . (isset($_GET['bajar']) ? 'attachment' : 'inline')
    . '; filename="' . $pdf->nombreArchivo() . '"');
header('Content-Length: ' . strlen($bin));
header('X-Content-Type-Options: nosniff');
echo $bin;
