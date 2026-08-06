<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('kardex.ver');

$productoId = (int) ($_GET['producto_id'] ?? 0);
$filtros = [
    'almacen_id' => $_GET['almacen_id'] ?? '',
    'desde'      => $_GET['desde'] ?? '',
    'hasta'      => $_GET['hasta'] ?? '',
    'tipo'       => $_GET['tipo'] ?? '',
];

if ($productoId) {
    $producto = Producto::buscar($productoId);
    if (!$producto) {
        Sesion::flash('error', 'Producto no encontrado.');
        Vista::redirigir('kardex.php');
    }
    $movimientos = Kardex::porProducto(
        $productoId,
        $filtros['almacen_id'] ? (int) $filtros['almacen_id'] : null,
        $filtros['desde'] ?: null,
        $filtros['hasta'] ?: null
    );
    Vista::render('kardex/producto', [
        'producto'    => $producto,
        'movimientos' => $movimientos,
        'filtros'     => $filtros,
        'almacenes'   => Catalogo::opciones('almacenes'),
    ], 'Kardex — ' . $producto['descripcion']);
    exit;
}

Vista::render('kardex/general', [
    'movimientos' => Kardex::general($filtros),
    'filtros'     => $filtros,
    'almacenes'   => Catalogo::opciones('almacenes'),
], 'Kardex general');
