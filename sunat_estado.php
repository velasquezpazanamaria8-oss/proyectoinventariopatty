<?php
/**
 * Estado de la integración con SUNAT: en qué punto está cada período y qué
 * lo detiene. Sin esta pantalla, cada caso raro obliga a consultar la base.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('sunat.gestionar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    try {
        if (($_POST['op'] ?? '') === 'reintentar') {
            $n = SunatCpeItem::reintentar(preg_replace('/\D/', '', (string) $_POST['periodo']));
            Sesion::flash('ok', "Se reactivaron $n comprobante(s) para reintentar la descarga.");
        }
        if (($_POST['op'] ?? '') === 'ejecutar') {
            set_time_limit(300);
            $r = SunatTarea::ejecutar(120, 'manual');
            Sesion::flash($r['ok'] ? 'ok' : 'warning', $r['mensaje']);
        }
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('sunat_estado.php');
}

// Embudo por período: qué hay en cada etapa
$periodos = [];
foreach (SunatComprobante::periodosSincronizados() as $per => $s) {
    $descarga = SunatCpeItem::avance($per);

    $periodos[$per] = [
        'comprobantes' => (int) $s['ventas'] + (int) $s['compras'],
        'ventas'       => (int) $s['ventas'],
        'compras'      => (int) $s['compras'],
        'sincronizado' => $s['ultima'],
        'descargados'  => $descarga['ok'],
        'sin_descargar'=> $descarga['pendientes'] + $descarga['error'],
        'lineas'       => $descarga['items'],
        'sin_conciliar'=> (int) DB::valor(
            'SELECT COUNT(*) FROM sunat_cpe_items i JOIN sunat_comprobantes c ON c.id = i.cpe_id
              WHERE i.empresa_id = :e AND c.periodo = :p AND i.producto_id IS NULL',
            [':e' => Empresa::id(), ':p' => $per]),
        'generados'    => (int) DB::valor(
            'SELECT COUNT(*) FROM sunat_comprobantes
              WHERE ' . Empresa::filtro() . ' AND periodo = :p AND mov_id IS NOT NULL',
            Empresa::param() + [':p' => $per]),
    ];
}

Vista::render('sunat/estado', [
    'periodos'  => $periodos,
    'historial' => SunatTarea::historial(12),
    'fallidos'  => DB::todos(
        'SELECT periodo, tipo, serie, numero, descarga_estado, descarga_intentos,
                descarga_msg, descargado_en
           FROM sunat_comprobantes
          WHERE ' . Empresa::filtro() . " AND descarga_estado = 'ERROR'
          ORDER BY descargado_en DESC LIMIT 40", Empresa::param()),
    'cronClave' => (string) Config::get('app.cron_clave', ''),
], 'Estado de la integración');
