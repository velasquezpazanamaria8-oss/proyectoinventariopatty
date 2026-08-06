<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requiereLogin();

$resumen  = Reporte::resumen();
$alertas  = array_slice(Producto::stockMinimo(), 0, 10);
$ultimos  = Kardex::general([], 12);

Vista::render('panel/index', compact('resumen', 'alertas', 'ultimos'), 'Panel de control');
