<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('ajustes.registrar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    try {
        Ajuste::registrar([
            'fecha'          => $_POST['fecha'],
            'almacen_id'     => (int) $_POST['almacen_id'],
            'producto_id'    => (int) $_POST['producto_id'],
            'tipo'           => $_POST['tipo'],
            'cantidad'       => (float) $_POST['cantidad'],
            'costo_unitario' => (float) ($_POST['costo_unitario'] ?? 0),
            'motivo'         => $_POST['motivo'] ?? '',
        ]);
        Sesion::flash('ok', 'Ajuste registrado y stock actualizado.');
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('ajustes.php');
}

Vista::render('ajustes/index', [
    'ajustes'   => Ajuste::listar(['desde' => $_GET['desde'] ?? '', 'hasta' => $_GET['hasta'] ?? '']),
    'almacenes' => Catalogo::opciones('almacenes'),
], 'Ajustes de inventario');
