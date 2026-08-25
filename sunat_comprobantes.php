<?php
/**
 * Comprobantes del SIRE: sincronizar un período y consultarlo.
 * Esta pantalla NO toca el inventario: sólo lee de SUNAT y guarda.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('sunat.gestionar');

if (!CredencialSunat::existe()) {
    Sesion::flash('warning', 'Primero configure las credenciales SUNAT de esta empresa.');
    Vista::redirigir('sunat.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    $periodo = '';
    try {
        $periodo = preg_replace('/\D/', '', (string) ($_POST['periodo'] ?? ''));
        if (strlen($periodo) !== 6) {
            throw new InvalidArgumentException('Período inválido.');
        }
        // Puede tardar: son varias llamadas a SUNAT con reintentos.
        set_time_limit(300);

        $sincronizacion = SunatSire::sincronizar(CredencialSunat::descifradas(), $periodo);

        $v = $sincronizacion['tipos']['ventas'];
        $c = $sincronizacion['tipos']['compras'];
        Sesion::flash('ok', sprintf(
            'Período %s sincronizado: %d venta(s) y %d compra(s).',
            $periodo, $v['guardados'], $c['guardados']));
        // El resumen viaja por la sesión: la respuesta se pinta tras redirigir.
        Sesion::set('sire_resumen', $sincronizacion);

    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }

    // Redirección después del POST: si no, al pulsar F5 el navegador reenvía el
    // formulario y se vuelve a consultar todo el período al SIRE sin querer.
    Vista::redirigir('sunat_comprobantes.php' . ($periodo ? '?periodo=' . $periodo : ''));
}

// Resumen de la última sincronización, consumido una sola vez.
$sincronizacion = Sesion::get('sire_resumen');
Sesion::quitar('sire_resumen');

// Períodos que ofrece SUNAT (se consulta sólo si hace falta pintarlos)
$periodosSunat = [];
try {
    $periodosSunat = SunatSire::periodos(CredencialSunat::descifradas());
} catch (Throwable $e) {
    Sesion::flash('warning', 'No se pudieron leer los períodos del SIRE: ' . $e->getMessage());
}

$filtros = [
    'periodo' => preg_replace('/\D/', '', (string) ($_GET['periodo'] ?? ($_POST['periodo'] ?? ''))),
    'tipo'    => $_GET['tipo'] ?? '',
    'cod'     => $_GET['cod'] ?? '',
    'q'       => trim($_GET['q'] ?? ''),
];

Vista::render('sunat/comprobantes', [
    'periodosSunat'  => $periodosSunat,
    'sincronizados'  => SunatComprobante::periodosSincronizados(),
    'comprobantes'   => SunatComprobante::listar($filtros),
    'totales'        => SunatComprobante::totales($filtros),
    'filtros'        => $filtros,
    'sincronizacion' => $sincronizacion,
], 'Comprobantes SUNAT');
