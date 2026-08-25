<?php
/**
 * Fase 4: convertir los comprobantes de SUNAT en movimientos de inventario.
 * Esta pantalla SÍ modifica el stock.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('sunat.gestionar');
Auth::requierePermiso('entradas.registrar');   // genera movimientos reales

$almacenes = Catalogo::opciones('almacenes');
$almacenId = (int) ($_REQUEST['almacen_id'] ?? array_key_first($almacenes));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    set_time_limit(300);
    try {
        if (($_POST['op'] ?? '') === 'inicial') {
            $r = GeneradorMovimientos::aplicarStockInicial($almacenId);
            Sesion::flash($r['aplicados'] ? 'ok' : 'warning', $r['aplicados']
                ? "Saldo inicial aplicado a {$r['aplicados']} producto(s), con fecha {$r['fecha']}."
                : 'No había stock inicial pendiente de aplicar.');
        }

        if (($_POST['op'] ?? '') === 'recalcular') {
            $r = Kardex::recalcularSaldos();
            Sesion::flash($r['corregidos'] ? 'ok' : 'warning', $r['corregidos']
                ? "Saldos recalculados: {$r['corregidos']} movimiento(s) corregidos en "
                  . "{$r['productos']} producto(s). Las cantidades y el valor del inventario no cambian."
                : 'Los saldos del kardex ya estaban en orden: no hizo falta corregir nada.');
        }

        if (($_POST['op'] ?? '') === 'deshacer') {
            $r = GeneradorMovimientos::deshacerTodo();
            Sesion::flash('ok', sprintf(
                'Se deshicieron los movimientos: %d del kardex, %d entrada(s) y %d salida(s). '
                . '%d comprobante(s) vuelven a estar por convertir y %d saldo(s) inicial(es) '
                . 'quedan pendientes. Revise los costos antes de volver a aplicarlos.',
                $r['kardex'], $r['entradas'], $r['salidas'], $r['comprobantes'], $r['inicial']));
        }

        if (($_POST['op'] ?? '') === 'generar') {
            $resultado = GeneradorMovimientos::generar($almacenId, [], 25);
            $ok = count(array_filter($resultado, fn($h) => $h['ok']));
            Sesion::flash('ok', 'Lote procesado: ' . $ok . ' de ' . count($resultado) . ' comprobante(s) convertidos.');
            // El detalle del lote viaja por la sesión porque la respuesta ya no
            // se pinta aquí, sino después de la redirección.
            Sesion::set('gen_resultado', $resultado);
        }
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }

    // Redirección después del POST. Sin esto, pulsar F5 reenvía el formulario y
    // se lanza OTRO lote de movimientos que nadie pidió: en esta pantalla eso
    // significa tocar el stock por accidente.
    Vista::redirigir('sunat_generar.php?almacen_id=' . $almacenId);
}

// Detalle del último lote, si se viene de generarlo. Se consume una sola vez:
// al recargar ya no reaparece, porque no correspondería a nada recién hecho.
$resultado = Sesion::get('gen_resultado');
Sesion::quitar('gen_resultado');

Vista::render('sunat/generar', [
    'revision'  => GeneradorMovimientos::revisar([]),
    'almacenes' => $almacenes,
    'almacenId' => $almacenId,
    'resultado' => $resultado,
    'fallidos'  => GeneradorMovimientos::fallidos(),
    'generados' => GeneradorMovimientos::generados(30),
    'inicialPendiente' => (int) DB::valor(
        'SELECT COUNT(*) FROM sunat_stock_inicial
          WHERE ' . Empresa::filtro() . ' AND aplicado_en IS NULL AND cantidad > 0',
        Empresa::param()),
    // De los pendientes, los que entrarían al kardex sin valor.
    'inicialSinCosto' => (int) DB::valor(
        'SELECT COUNT(*) FROM sunat_stock_inicial
          WHERE ' . Empresa::filtro() . ' AND aplicado_en IS NULL
            AND cantidad > 0 AND costo_unitario = 0',
        Empresa::param()),
    'inicialAplicado' => (int) DB::valor(
        'SELECT COUNT(*) FROM sunat_stock_inicial
          WHERE ' . Empresa::filtro() . ' AND aplicado_en IS NOT NULL',
        Empresa::param()),
    // Y los que YA entraron así: el aviso llega tarde, pero es peor no darlo.
    'inicialAplicadoSinCosto' => (int) DB::valor(
        'SELECT COUNT(*) FROM sunat_stock_inicial
          WHERE ' . Empresa::filtro() . ' AND aplicado_en IS NOT NULL
            AND cantidad > 0 AND costo_unitario = 0',
        Empresa::param()),
], 'Generar movimientos');
