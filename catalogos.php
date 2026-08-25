<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('catalogos.ver');

$tabla = $_GET['t'] ?? 'categorias';
if (!Catalogo::valida($tabla)) {
    Sesion::flash('error', 'Catálogo no válido.');
    Vista::redirigir('catalogos.php?t=categorias');
}
$meta = Catalogo::TABLAS[$tabla];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requierePermiso('catalogos.gestionar');
    Csrf::verificar();

    // Traer los clientes de las ventas ya importadas evita teclear a mano lo
    // que SUNAT ya tiene, con su RUC y su razón social exactos.
    if (($_POST['op'] ?? '') === 'sembrar_clientes') {
        try {
            $r = Cliente::sembrarDesdeVentas();
            Sesion::flash($r['creados'] ? 'ok' : 'warning', $r['creados']
                ? "Se agregaron {$r['creados']} cliente(s) desde sus ventas."
                  . ($r['existentes'] ? " Otros {$r['existentes']} ya estaban." : '')
                  . ($r['sin_ruc'] ? " {$r['sin_ruc']} venta(s) sin RUC quedaron fuera." : '')
                : 'No había clientes nuevos que traer desde las ventas.');
        } catch (Throwable $e) {
            Sesion::flash('error', $e->getMessage());
        }
        Vista::redirigir('catalogos.php?t=clientes');
    }

    try {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        Catalogo::guardar($tabla, $_POST, $id);
        Sesion::flash('ok', $id ? 'Registro actualizado.' : 'Registro creado.');
    } catch (PDOException $e) {
        Sesion::flash('error', $e->errorInfo[1] == 1062 ? 'Ya existe un registro con ese nombre o código.' : $e->getMessage());
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('catalogos.php?t=' . $tabla);
}

if (($_GET['a'] ?? '') === 'eliminar') {
    Auth::requierePermiso('catalogos.gestionar');
    try {
        Catalogo::eliminar($tabla, (int) $_GET['id']);
        Sesion::flash('ok', 'Registro eliminado.');
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('catalogos.php?t=' . $tabla);
}

$editar = !empty($_GET['id']) ? Catalogo::buscar($tabla, (int) $_GET['id']) : null;

Vista::render('catalogos/index', [
    'tabla'   => $tabla,
    'meta'    => $meta,
    'filas'   => Catalogo::listar($tabla, trim($_GET['q'] ?? '')),
    'editar'  => $editar,
    'q'       => trim($_GET['q'] ?? ''),
    // Sólo tiene sentido en clientes, y sólo si hay ventas de donde sacarlos.
    'porSembrar' => $tabla === 'clientes' ? Cliente::porSembrar() : 0,
], $meta['etiqueta']);
