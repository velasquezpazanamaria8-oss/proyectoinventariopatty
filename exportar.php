<?php
/**
 * Punto único de exportación de reportes a PDF y Excel. RF-12, RF-14.
 *
 *   exportar.php?r=valorizado&f=pdf&almacen_id=1
 *   exportar.php?r=kardex_producto&f=xlsx&producto_id=7
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requiereLogin();

$reporte = $_GET['r'] ?? '';
$formato = $_GET['f'] ?? 'pdf';

$filtros = [
    'desde'         => $_GET['desde'] ?? '',
    'hasta'         => $_GET['hasta'] ?? '',
    'almacen_id'    => $_GET['almacen_id'] ?? '',
    'categoria_id'  => $_GET['categoria_id'] ?? '',
    'marca_id'      => $_GET['marca_id'] ?? '',
    'motivo'        => $_GET['motivo'] ?? '',
    'tipo'          => $_GET['tipo'] ?? '',
    'q'             => trim($_GET['q'] ?? ''),
    'producto_id'   => $_GET['producto_id'] ?? '',
    'inventario_id' => $_GET['inventario_id'] ?? '',
];

try {
    if ($formato === 'xlsx') {
        Exportador::aExcel($reporte, $filtros);
    }
    Exportador::aPdf($reporte, $filtros);
} catch (InvalidArgumentException $e) {
    http_response_code(404);
    Sesion::flash('error', $e->getMessage());
    Vista::redirigir('reportes.php');
} catch (Throwable $e) {
    http_response_code(500);
    Sesion::flash('error', 'No se pudo generar el archivo: ' . $e->getMessage());
    Vista::redirigir('reportes.php');
}
