<?php
/**
 * Cotizaciones de la empresa activa.
 *
 * Las líneas pueden venir del catálogo o escribirse a mano: de las nueve
 * empresas del grupo sólo una lleva inventario, y las demás cotizan como lo
 * hacían en su Excel.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('cotizaciones.gestionar');

$accion = $_GET['a'] ?? 'listar';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    $op = $_POST['op'] ?? 'guardar';
    $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;

    try {
        if ($op === 'estado') {
            Cotizacion::cambiarEstado((int) $_POST['id'], (string) $_POST['estado']);
            Sesion::flash('ok', 'Cotización marcada como '
                . mb_strtolower(Cotizacion::ESTADOS[$_POST['estado']]) . '.');
            Vista::redirigir('cotizaciones.php?a=ver&id=' . (int) $_POST['id']);
        }

        if ($op === 'duplicar') {
            $nueva = Cotizacion::duplicar((int) $_POST['id']);
            Sesion::flash('ok', 'Se creó una copia en borrador; ajústela antes de enviarla.');
            Vista::redirigir('cotizaciones.php?a=form&id=' . $nueva);
        }

        if ($op === 'eliminar') {
            Cotizacion::eliminar((int) $_POST['id']);
            Sesion::flash('ok', 'Cotización eliminada.');
            Vista::redirigir('cotizaciones.php');
        }

        // Alta o edición: las líneas llegan como arreglos paralelos.
        $lineas = [];
        foreach ($_POST['descripcion'] ?? [] as $i => $desc) {
            $lineas[] = [
                'producto_id'     => $_POST['producto_id'][$i] ?? null,
                'descripcion'     => $desc,
                'unidad'          => $_POST['unidad'][$i] ?? '',
                'cantidad'        => $_POST['cantidad'][$i] ?? 0,
                'precio_unitario' => $_POST['precio'][$i] ?? 0,
            ];
        }
        $guardada = Cotizacion::guardar($_POST, $lineas, $id);
        Sesion::flash('ok', $id ? 'Cotización actualizada.' : 'Cotización creada.');
        Vista::redirigir('cotizaciones.php?a=ver&id=' . $guardada);

    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
        Vista::redirigir('cotizaciones.php' . ($id ? '?a=form&id=' . $id : '?a=form'));
    }
}

$cfg = CotizacionConfig::actual();

if ($accion === 'form') {
    $cot = !empty($_GET['id']) ? Cotizacion::buscar((int) $_GET['id']) : null;
    if ($cot && !Cotizacion::editable($cot)) {
        Sesion::flash('warning', 'Esa cotización ya no es un borrador: sólo puede verse.');
        Vista::redirigir('cotizaciones.php?a=ver&id=' . $cot['id']);
    }
    Vista::render('cotizaciones/form', [
        'cot'      => $cot,
        'cfg'      => $cfg,
        'clientes' => Catalogo::listar('clientes'),
        'numero'   => $cot ? (int) $cot['numero'] : Cotizacion::siguienteNumero(),
        // El buscador de productos exige un almacén de la empresa activa; en
        // una cotización da igual cuál, pero tiene que ser suyo.
        'almacenId' => (int) array_key_first(Catalogo::opciones('almacenes')),
    ], $cot ? 'Editar cotización' : 'Nueva cotización');
}

if ($accion === 'ver') {
    $cot = Cotizacion::buscar((int) ($_GET['id'] ?? 0));
    if (!$cot) {
        Sesion::flash('error', 'Esa cotización no es de la empresa activa.');
        Vista::redirigir('cotizaciones.php');
    }
    Vista::render('cotizaciones/ver', [
        'cot' => $cot,
        'cfg' => $cfg,
    ], 'Cotización ' . CotizacionConfig::formatoNumero($cfg, (int) $cot['numero']));
}

$filtros = [
    'estado'     => $_GET['estado'] ?? '',
    'cliente_id' => $_GET['cliente_id'] ?? '',
    'desde'      => $_GET['desde'] ?? '',
    'hasta'      => $_GET['hasta'] ?? '',
    'q'          => trim($_GET['q'] ?? ''),
];

Vista::render('cotizaciones/lista', [
    'cotizaciones' => Cotizacion::listar($filtros),
    'clientes'     => Catalogo::opciones('clientes'),
    'filtros'      => $filtros,
    'cfg'          => $cfg,
], 'Cotizaciones');
