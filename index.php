<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requiereLogin();

$resumen  = Reporte::resumen();
$alertas  = array_slice(Producto::stockMinimo(), 0, 10);
$ultimos  = Kardex::general([], 12);

// Aviso de SUNAT: sin esto, un comprobante sin conciliar o una venta que no
// se pudo generar por falta de stock se quedan invisibles hasta que alguien
// se acuerde de entrar a esas pantallas a revisar.
$sunatPendiente = null;
if (CredencialSunat::existe()) {
    $sinDecidir = Conciliacion::avance()['sin_decidir'];
    $fallidos   = GeneradorMovimientos::contarFallidos();
    if ($sinDecidir > 0 || $fallidos > 0) {
        $sunatPendiente = ['sin_decidir' => $sinDecidir, 'fallidos' => $fallidos];
    }
}

Vista::render('panel/index', compact('resumen', 'alertas', 'ultimos', 'sunatPendiente'), 'Panel de control');
