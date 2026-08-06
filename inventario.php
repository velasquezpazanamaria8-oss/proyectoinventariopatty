<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('inventario.ver');

$filtros = [
    'almacen_id'   => $_GET['almacen_id'] ?? '',
    'categoria_id' => $_GET['categoria_id'] ?? '',
    'q'            => trim($_GET['q'] ?? ''),
];

$filas = Reporte::valorizado($filtros);

Vista::render('inventario/index', [
    'filas'      => $filas,
    'filtros'    => $filtros,
    'almacenes'  => Catalogo::opciones('almacenes'),
    'categorias' => Catalogo::opciones('categorias'),
    'total'      => array_sum(array_column($filas, 'valor')),
], 'Stock actual');
