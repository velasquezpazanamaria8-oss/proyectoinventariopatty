<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('salidas.ver');

$accion = $_GET['a'] ?? 'listar';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requierePermiso('salidas.registrar');
    Csrf::verificar();
    try {
        $items = [];
        foreach ($_POST['producto_id'] ?? [] as $i => $pid) {
            if ($pid === '') continue;
            $items[] = ['producto_id' => (int) $pid, 'cantidad' => (float) $_POST['cantidad'][$i]];
        }
        $id = Salida::registrar([
            'fecha'       => $_POST['fecha'],
            'almacen_id'  => (int) $_POST['almacen_id'],
            'motivo'      => $_POST['motivo'],
            'destino'     => $_POST['destino'] ?? '',
            'observacion' => $_POST['observacion'] ?? '',
        ], $items);
        Sesion::flash('ok', 'Salida registrada y stock actualizado.');
        Vista::redirigir('salidas.php?a=ver&id=' . $id);
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
        $accion = 'form';
    }
}

if ($accion === 'ver') {
    $salida = Salida::buscar((int) $_GET['id']);
    if (!$salida) { Sesion::flash('error', 'Salida no encontrada.'); Vista::redirigir('salidas.php'); }
    Vista::render('salidas/ver', ['salida' => $salida], 'Salida ' . $salida['serie_numero']);
    exit;
}

if ($accion === 'form') {
    Auth::requierePermiso('salidas.registrar');
    Vista::render('salidas/form', ['almacenes' => Catalogo::opciones('almacenes')], 'Nueva salida de almacén');
    exit;
}

$filtros = [
    'desde'      => $_GET['desde'] ?? '',
    'hasta'      => $_GET['hasta'] ?? '',
    'almacen_id' => $_GET['almacen_id'] ?? '',
    'motivo'     => $_GET['motivo'] ?? '',
    'q'          => trim($_GET['q'] ?? ''),
];
Vista::render('salidas/lista', [
    'salidas'   => Salida::listar($filtros),
    'filtros'   => $filtros,
    'almacenes' => Catalogo::opciones('almacenes'),
], 'Salidas de almacén');
