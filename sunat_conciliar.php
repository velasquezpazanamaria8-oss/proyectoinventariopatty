<?php
/**
 * Conciliación: emparejar los productos de SUNAT con el catálogo, y capturar
 * el stock inicial. Sigue sin mover inventario — eso es la fase siguiente.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('sunat.gestionar');

if (!CredencialSunat::existe()) {
    Sesion::flash('warning', 'Primero configure las credenciales SUNAT.');
    Vista::redirigir('sunat.php');
}

$almacenes = Catalogo::opciones('almacenes');
$almacenId = (int) ($_REQUEST['almacen_id'] ?? array_key_first($almacenes));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    try {
        if (($_POST['op'] ?? '') === 'sugerencias') {
            $n = Conciliacion::aplicarSugerencias(['periodo' => $_POST['periodo'] ?? '']);
            Sesion::flash('ok', $n
                ? "Se emparejaron $n producto(s) que ya existían con el mismo código."
                : 'No había sugerencias por código exacto para aplicar.');
        }
        if (($_POST['op'] ?? '') === 'ignorar_conceptos') {
            $n = Conciliacion::ignorarConceptos([
                'periodo' => $_POST['periodo'] ?? '', 'tipo' => $_POST['tipo'] ?? '']);
            Sesion::flash('ok', $n
                ? "Se marcaron $n concepto(s) como «no es inventario»: anticipos, comisiones, "
                  . 'detracciones y demás. Si alguno sí era un producto, use «Cambiar» en su fila.'
                : 'No quedaba ningún concepto de ésos sin decidir.');
        }
        if (($_POST['op'] ?? '') === 'stock_inicial') {
            $almacen = (int) $_POST['almacen_id'];
            $n = Conciliacion::guardarStockInicial(
                $almacen, $_POST['cantidad'] ?? [], $_POST['costo'] ?? []);
            Sesion::flash('ok', "Stock inicial guardado para $n producto(s). "
                . 'Se aplicará al generar los movimientos.');

            // Un saldo inicial con costo cero entra al kardex sin valor: el
            // inventario aparece valorizado por debajo y, al vender, el costo
            // de ventas sale mal. Se permite —puede ser material recibido sin
            // costo— pero no debe pasar inadvertido.
            $sinCosto = Conciliacion::sinCostoInicial($almacen);
            if ($sinCosto) {
                Sesion::flash('warning', $sinCosto . ' producto(s) quedaron con cantidad '
                    . 'pero costo 0: entrarán al kardex sin valor y el inventario quedará '
                    . 'valorizado por debajo de lo que vale. Revise la columna «Costo unitario».');
            }
        }
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('sunat_conciliar.php?almacen_id=' . $almacenId
        . (!empty($_POST['periodo']) ? '&periodo=' . $_POST['periodo'] : '')
        . (!empty($_POST['tipo']) ? '&tipo=' . urlencode($_POST['tipo']) : ''));
}

$filtros = [
    'periodo' => preg_replace('/\D/', '', (string) ($_GET['periodo'] ?? '')),
    'tipo'    => $_GET['tipo'] ?? '',
];

$items  = Conciliacion::pendientes($filtros);
$avance = Conciliacion::avance($filtros);

// Ver sólo lo que no parece producto: con cientos de filas, los anticipos y
// las comisiones se pierden entre la mercadería y nunca se deciden.
$soloNoProducto = !empty($_GET['no_producto']);
if ($soloNoProducto) {
    $items = array_values(array_filter($items, fn($i) => (bool) $i['no_inventario']));
}

Vista::render('sunat/conciliar', [
    'items'      => $items,
    'avance'     => $avance,
    'soloNoProd' => $soloNoProducto,
    'periodos'   => SunatComprobante::periodosSincronizados(),
    'filtros'    => $filtros,
    'almacenes'  => $almacenes,
    'almacenId'  => $almacenId,
    'stock'      => Conciliacion::stockInicial($almacenId),
    // Catálogos para el formulario de alta de producto.
    'categorias' => Catalogo::opciones('categorias'),
    'marcas'     => Catalogo::opciones('marcas'),
    'unidades'   => Catalogo::opciones('unidades'),
], 'Conciliar productos');
