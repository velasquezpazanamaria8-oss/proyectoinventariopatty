<?php
/**
 * Vista previa del diseño de cotización: el PDF tal como quedaría con lo que
 * hay ahora mismo en el formulario, sin guardar nada.
 *
 * Se sirve dentro de un <iframe> del propio diseñador. Los datos son de
 * ejemplo a propósito: lo que se está revisando es la plantilla, no una
 * cotización concreta, y una empresa recién creada todavía no tiene ninguna
 * que enseñar.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('cotizaciones.gestionar');

// Sólo por POST: la previa lleva dentro lo que el usuario está escribiendo, y
// eso no tiene por qué acabar en el historial del navegador ni en los logs.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Vista::redirigir('cotizacion_diseno.php');
}
Csrf::verificar();

$cfg = CotizacionConfig::desdeFormulario($_POST);
$emp = Empresa::ficha();

/** Cotización de muestra: pocas líneas, pero de las que suelen dar problemas. */
$detalle = [
    ['unidad' => 'UND', 'cantidad' => 12, 'descripcion' => 'Casco de seguridad dieléctrico con barbiquejo, color blanco', 'precio_unitario' => 28.50],
    ['unidad' => 'PAR', 'cantidad' => 40, 'descripcion' => 'Guantes de badana reforzados', 'precio_unitario' => 9.90],
    ['unidad' => 'GLN', 'cantidad' => 6,  'descripcion' => 'Pintura esmalte sintético', 'precio_unitario' => 62.00],
    ['unidad' => 'SERV', 'cantidad' => 1, 'descripcion' => 'Servicio de instalación y puesta en marcha, incluye traslado de personal, materiales de consumo y certificado de operatividad', 'precio_unitario' => 1450.00],
];

$subtotal = 0;
foreach ($detalle as &$d) {
    $d['importe'] = round($d['cantidad'] * $d['precio_unitario'], 2);
    $subtotal += $d['importe'];
}
unset($d);

// Si la empresa cotiza con IGV incluido, el precio ya lo trae dentro y hay que
// separarlo; si no, se le suma. En los dos casos el TOTAL es lo mismo que
// pagará el cliente, que es lo que se está revisando.
$igvIncluido = !empty($cfg['incluye_igv']);
$total    = $igvIncluido ? $subtotal : round($subtotal * (1 + Cotizacion::IGV), 2);
$subtotal = $igvIncluido ? round($total / (1 + Cotizacion::IGV), 2) : $subtotal;
$igv      = round($total - $subtotal, 2);

$cot = [
    'numero'            => 25,
    'fecha'             => date('Y-m-d'),
    'valida_hasta'      => date('Y-m-d', strtotime('+15 days')),
    'referencia'        => 'EJEMPLO-2026',
    'cliente_nombre'    => 'CLIENTE DE EJEMPLO S.A.C.',
    'cliente_direccion' => 'Av. Los Constructores 1234, Urb. Santa Patricia, La Molina - Lima',
    'cliente_ruc'       => '20100000001',
    'cliente_email'     => 'compras@ejemplo.com.pe',
    'detalle'           => $detalle,
    'subtotal'          => $subtotal,
    'igv'               => $igv,
    'total'             => $total,
];

$bin = (new CotizacionPdf($cot, $cfg, $emp))->generar();

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="previa.pdf"');
header('Content-Length: ' . strlen($bin));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $bin;
