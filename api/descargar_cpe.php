<?php
/**
 * Procesa un LOTE de descargas y devuelve el avance.
 *
 * La pantalla lo llama repetidamente por AJAX. Se trabaja por lotes con un
 * presupuesto de tiempo en vez de descargarlo todo en una petición: SUNAT
 * tarda entre 2 y 50 segundos por comprobante y el proxy del hosting mata el
 * proceso mucho antes, dejando el trabajo a medias y sin registrar el error.
 *
 * Cada comprobante se guarda al terminarlo: si el proceso muere se pierde uno,
 * no el lote entero, y la barra de progreso avanza de verdad.
 */
require_once __DIR__ . '/../bootstrap.php';

if (!Auth::autenticado() || !Auth::puede('sunat.gestionar')) {
    Vista::json(['ok' => false, 'error' => 'Sin permisos'], 403);
}
// Este endpoint escribe (archivos, líneas y contador de intentos), así que
// necesita token aunque sea un GET: si no, basta una <img> en otra página para
// que el navegador de un administrador queme los intentos de todo un período.
if (!Csrf::valido($_GET['_csrf'] ?? null)) {
    Vista::json(['ok' => false, 'error' => 'Petición no válida'], 400);
}

$periodo = preg_replace('/\D/', '', (string) ($_GET['periodo'] ?? ''));
if (strlen($periodo) !== 6) {
    Vista::json(['ok' => false, 'error' => 'Período inválido'], 400);
}

// Parar es sólo quitar la marca: el lote que esté en curso termina su trabajo
// (no se tira lo ya empezado), pero no se pedirá ninguno más.
if (($_GET['op'] ?? '') === 'parar') {
    DescargaEnMarcha::quitar($periodo);
    Vista::json(['ok' => true, 'parado' => true]);
}

// El lote NO se abandona porque el navegador se vaya. Si el usuario recarga la
// página o cierra la pestaña en mitad de una descarga, PHP sigue hasta terminar
// los comprobantes que tenía entre manos y los guarda: de lo contrario se
// perdería el que estuviera a medias y habría que gastar otro intento.
ignore_user_abort(true);

DescargaEnMarcha::marcar($periodo);

// Un solo lote por empresa a la vez.
//
// Si el usuario recarga la pantalla en mitad de una descarga, el lote que había
// en curso NO muere: el navegador se desentiende, pero PHP sigue bajando
// comprobantes hasta agotar su presupuesto. Sin este cerrojo, al pulsar otra vez
// "Descargar pendientes" habría dos procesos pidiendo los MISMOS comprobantes a
// SUNAT, gastando intentos por duplicado.
//
// Se usa GET_LOCK y no una marca en una tabla porque se suelta solo al cerrarse
// la conexión: si el proceso muere, la empresa no queda bloqueada para siempre.
$cerrojo = 'cpe_' . Empresa::id();
if ((int) DB::valor('SELECT GET_LOCK(:c, 0)', [':c' => $cerrojo]) !== 1) {
    Vista::json([
        'ok'    => false,
        'error' => 'Ya hay una descarga en curso para esta empresa. '
                 . 'Si acaba de recargar la página, espere unos segundos a que termine el lote anterior.',
    ], 409);
}
register_shutdown_function(static function () use ($cerrojo): void {
    DB::valor('SELECT RELEASE_LOCK(:c)', [':c' => $cerrojo]);
});

// Presupuesto de tiempo del lote: se corta antes de que el servidor lo haga.
$limite = 45;
$inicio = microtime(true);
set_time_limit($limite + 30);

// Si faltan credenciales o la clave maestra no corresponde, esto lanza. Sin
// capturarlo la respuesta sería un fatal en HTML y el navegador sólo vería
// "se cortó la conexión", ocultando la causa real.
try {
    $cred = CredencialSunat::descifradas();
} catch (Throwable $e) {
    Vista::json(['ok' => false, 'error' => $e->getMessage()], 400);
}

$hechos = [];

foreach (SunatCpeItem::pendientes($periodo, 25) as $cpe) {
    if (microtime(true) - $inicio > $limite) {
        break;                       // se sigue en la próxima llamada
    }
    try {
        $r = SunatCpe::descargar($cred, $cpe);

        if ($r['error'] !== null) {
            SunatCpeItem::anotarError((int) $cpe['id'], $r['error']);
            $hechos[] = ['ref' => $r['ref'], 'ok' => false, 'msg' => $r['error']];
        } else {
            $hechos[] = [
                'ref'   => $r['ref'],
                'ok'    => true,
                'items' => $r['items'],
                'falta' => implode(',', array_keys($r['faltan'])),
            ];
        }
    } catch (Throwable $e) {
        SunatCpeItem::anotarError((int) $cpe['id'], $e->getMessage());
        $hechos[] = ['ref' => $cpe['serie'] . '-' . $cpe['numero'], 'ok' => false, 'msg' => $e->getMessage()];
    }
}

$avance = SunatCpeItem::avance($periodo);
$terminado = $avance['por_intentar'] === 0 || !$hechos;

// Al acabar se retira la marca: si no, la pantalla intentaría retomar sola una
// descarga que ya no tiene nada que hacer.
$terminado ? DescargaEnMarcha::quitar($periodo) : DescargaEnMarcha::tocar($periodo);

Vista::json([
    'ok'       => true,
    'avance'   => $avance,
    'hechos'   => $hechos,
    // Se termina cuando ya no queda nada QUE REINTENTAR, no cuando no quedan
    // "pendientes": los errores recuperables no cuentan como pendientes pero
    // sí se vuelven a intentar. Si el lote no logró avanzar nada tampoco se
    // sigue, para no dejar al navegador en un bucle de peticiones.
    'terminado' => $terminado,
]);
