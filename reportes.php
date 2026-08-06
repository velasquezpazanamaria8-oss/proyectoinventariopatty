<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('reportes.ver');

$reporte = $_GET['r'] ?? '';
$desde   = $_GET['desde'] ?? date('Y-m-01');
$hasta   = $_GET['hasta'] ?? date('Y-m-d');
$almacen = $_GET['almacen_id'] ?? '';

$datos = [];
$titulo = 'Reportes';

switch ($reporte) {
    case 'stock_minimo':
        $titulo = 'Productos con stock mínimo';
        $datos  = Producto::stockMinimo($almacen ? (int) $almacen : null);
        break;

    case 'valorizado':
        Auth::requierePermiso('reportes.valorizado');
        $titulo = 'Inventario valorizado';
        $datos  = Reporte::valorizado(['almacen_id' => $almacen]);
        break;

    case 'entradas':
        $titulo = 'Entradas por fecha';
        $datos  = Entrada::listar(['desde' => $desde, 'hasta' => $hasta, 'almacen_id' => $almacen], 1000);
        break;

    case 'salidas':
        $titulo = 'Salidas por fecha';
        $datos  = Salida::listar(['desde' => $desde, 'hasta' => $hasta, 'almacen_id' => $almacen], 1000);
        break;

    case 'por_usuario':
        $titulo = 'Movimientos por usuario';
        $datos  = Reporte::porUsuario($desde, $hasta);
        break;

    case 'por_categoria':
        $titulo = 'Inventario por categoría';
        $datos  = Reporte::porCategoria($almacen ? (int) $almacen : null);
        break;

    case 'por_almacen':
        $titulo = 'Inventario por almacén';
        $datos  = Reporte::porAlmacen();
        break;
}

Vista::render('reportes/index', [
    'reporte'   => $reporte,
    'datos'     => $datos,
    'desde'     => $desde,
    'hasta'     => $hasta,
    'almacen'   => $almacen,
    'almacenes' => Catalogo::opciones('almacenes'),
], $titulo);
