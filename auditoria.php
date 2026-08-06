<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('auditoria.ver');

$filtros = [
    'usuario_id' => $_GET['usuario_id'] ?? '',
    'accion'     => $_GET['accion'] ?? '',
    'desde'      => $_GET['desde'] ?? '',
    'hasta'      => $_GET['hasta'] ?? '',
];

Vista::render('auditoria/index', [
    'registros' => Auditoria::listar($filtros),
    'filtros'   => $filtros,
    'usuarios'  => array_column(Usuario::listar(), 'usuario', 'id'),
], 'Auditoría del sistema');
