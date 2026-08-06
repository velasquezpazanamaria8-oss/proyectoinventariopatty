<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('inventario.ver');

$accion = $_GET['a'] ?? 'listar';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requierePermiso('inventario.gestionar');
    Csrf::verificar();
    $op = $_POST['op'] ?? '';

    try {
        if ($op === 'abrir') {
            $id = Inventario::abrir([
                'fecha'       => $_POST['fecha'],
                'almacen_id'  => (int) $_POST['almacen_id'],
                'observacion' => $_POST['observacion'] ?? '',
            ], !empty($_POST['solo_con_stock']));
            Sesion::flash('ok', 'Conteo abierto. El stock del sistema quedó congelado para la comparación.');
            Vista::redirigir('inventario_fisico.php?a=ver&id=' . $id);
        }

        if ($op === 'contar') {
            $id = (int) $_POST['inventario_id'];
            $n  = Inventario::guardarConteo($id, $_POST['fisico'] ?? []);
            Sesion::flash('ok', "Conteo guardado ($n líneas actualizadas).");
            Vista::redirigir('inventario_fisico.php?a=ver&id=' . $id);
        }

        if ($op === 'cerrar') {
            $id = (int) $_POST['inventario_id'];
            $r  = Inventario::cerrar($id);
            $msg = "Conteo cerrado. Se generaron {$r['ajustes']} ajuste(s); {$r['sin_cambio']} producto(s) coincidían.";
            if ($r['pendientes'] > 0) {
                $msg .= " Quedaron {$r['pendientes']} producto(s) sin contar: su stock no se modificó.";
            }
            Sesion::flash('ok', $msg);
            Vista::redirigir('inventario_fisico.php?a=ver&id=' . $id);
        }

        if ($op === 'anular') {
            Inventario::anular((int) $_POST['inventario_id']);
            Sesion::flash('ok', 'Conteo anulado. El stock no sufrió cambios.');
            Vista::redirigir('inventario_fisico.php');
        }
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
        Vista::redirigir('inventario_fisico.php' . (!empty($_POST['inventario_id'])
            ? '?a=ver&id=' . (int) $_POST['inventario_id'] : ''));
    }
}

if ($accion === 'ver') {
    $inv = Inventario::buscar((int) $_GET['id']);
    if (!$inv) {
        Sesion::flash('error', 'Conteo no encontrado.');
        Vista::redirigir('inventario_fisico.php');
    }
    Vista::render('inventario_fisico/detalle', [
        'inv'    => $inv,
        'filtro' => $_GET['ver'] ?? 'todos',
    ], 'Conteo ' . $inv['codigo']);
    exit;
}

$filtros = [
    'almacen_id' => $_GET['almacen_id'] ?? '',
    'estado'     => $_GET['estado'] ?? '',
];

Vista::render('inventario_fisico/lista', [
    'conteos'   => Inventario::listar($filtros),
    'filtros'   => $filtros,
    'almacenes' => Catalogo::opciones('almacenes'),
], 'Inventario físico');
