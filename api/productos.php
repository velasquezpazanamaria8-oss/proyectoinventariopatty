<?php
/**
 * Productos para los formularios de movimientos. RF-13.
 *
 *   ?q=cab&almacen_id=1              autocompletado del campo de escritura
 *   ?modo=lista&almacen_id=1&pag=2   listado paginado del buscador de productos
 */
require_once __DIR__ . '/../bootstrap.php';

if (!Auth::autenticado()) {
    Vista::json(['ok' => false, 'error' => 'No autenticado'], 401);
}
if (!Auth::puede('productos.ver')) {
    Vista::json(['ok' => false, 'error' => 'Sin permisos'], 403);
}

$q         = trim($_GET['q'] ?? '');
$almacenId = (int) ($_GET['almacen_id'] ?? Config::get('app.almacen_default', 1));

// El almacén debe ser de la empresa activa: si no, el stock mostrado sería ajeno.
$almacenValido = (int) DB::valor(
    'SELECT COUNT(*) FROM almacenes WHERE id = :a AND ' . Empresa::filtro(),
    Empresa::param() + [':a' => $almacenId]);
if (!$almacenValido) {
    Vista::json(['ok' => false, 'error' => 'Almacén no válido para la empresa activa'], 400);
}

// --- Buscador con ventana (admite término vacío y pagina) ---
if (($_GET['modo'] ?? '') === 'lista') {
    $r = Producto::buscador(
        $q, $almacenId,
        max(1, (int) ($_GET['pag'] ?? 1)), 12,
        !empty($_GET['categoria_id']) ? (int) $_GET['categoria_id'] : null
    );
    Vista::json([
        'ok'         => true,
        'datos'      => $r['filas'],
        'total'      => $r['total'],
        'pagina'     => $r['pagina'],
        'paginas'    => $r['paginas'],
        'categorias' => Catalogo::opciones('categorias'),
    ]);
}

// --- Autocompletado del campo de texto ---
if (mb_strlen($q) < 2) {
    Vista::json(['ok' => true, 'datos' => []]);
}
Vista::json(['ok' => true, 'datos' => Producto::autocompletar($q, $almacenId)]);
