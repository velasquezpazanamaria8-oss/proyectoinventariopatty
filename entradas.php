<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('entradas.ver');

$accion = $_GET['a'] ?? 'listar';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requierePermiso('entradas.registrar');
    Csrf::verificar();
    try {
        $items = [];
        foreach ($_POST['producto_id'] ?? [] as $i => $pid) {
            if ($pid === '') continue;
            $items[] = [
                'producto_id'    => (int) $pid,
                'cantidad'       => (float) $_POST['cantidad'][$i],
                'costo_unitario' => (float) $_POST['costo_unitario'][$i],
            ];
        }
        $id = Entrada::registrar([
            'fecha'          => $_POST['fecha'],
            'almacen_id'     => (int) $_POST['almacen_id'],
            'proveedor_id'   => $_POST['proveedor_id'] ?? '',
            'tipo_documento' => $_POST['tipo_documento'] ?? '',
            'nro_documento'  => $_POST['nro_documento'] ?? '',
            'observacion'    => $_POST['observacion'] ?? '',
        ], $items);
        Sesion::flash('ok', 'Entrada registrada y stock actualizado.');
        Vista::redirigir('entradas.php?a=ver&id=' . $id);
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
        $accion = 'form';
    }
}

if ($accion === 'ver') {
    $entrada = Entrada::buscar((int) $_GET['id']);
    if (!$entrada) { Sesion::flash('error', 'Entrada no encontrada.'); Vista::redirigir('entradas.php'); }
    Vista::render('entradas/ver', ['entrada' => $entrada], 'Entrada ' . $entrada['serie_numero']);
    exit;
}

if ($accion === 'form') {
    Auth::requierePermiso('entradas.registrar');
    Vista::render('entradas/form', [
        'almacenes'   => Catalogo::opciones('almacenes'),
        'proveedores' => Catalogo::opciones('proveedores'),
    ], 'Nueva entrada de almacén');
    exit;
}

$filtros = [
    'desde'      => $_GET['desde'] ?? '',
    'hasta'      => $_GET['hasta'] ?? '',
    'almacen_id' => $_GET['almacen_id'] ?? '',
    'q'          => trim($_GET['q'] ?? ''),
];
Vista::render('entradas/lista', [
    'entradas'  => Entrada::listar($filtros),
    'filtros'   => $filtros,
    'almacenes' => Catalogo::opciones('almacenes'),
], 'Entradas de almacén');
