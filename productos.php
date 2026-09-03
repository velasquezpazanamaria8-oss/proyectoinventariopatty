<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('productos.ver');

$accion = $_GET['a'] ?? 'listar';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requierePermiso('productos.gestionar');
    Csrf::verificar();
    $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    try {
        Producto::guardar($_POST, $id);
        Sesion::flash('ok', $id ? 'Producto actualizado.' : 'Producto registrado.');
        Vista::redirigir('productos.php');
    } catch (PDOException $e) {
        Sesion::flash('error', $e->errorInfo[1] == 1062
            ? 'Ya existe un producto con ese código.'
            : 'Error al guardar: ' . $e->getMessage());
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    $accion = 'form';
}

if ($accion === 'eliminar') {
    Auth::requierePermiso('productos.gestionar');
    try {
        Sesion::flash('ok', Producto::eliminar((int) $_GET['id']));
    } catch (Throwable $e) {
        Sesion::flash('error', 'No se pudo eliminar: ' . $e->getMessage());
    }
    Vista::redirigir('productos.php');
}

if ($accion === 'form') {
    Auth::requierePermiso('productos.gestionar');
    $producto = !empty($_GET['id']) ? Producto::buscar((int) $_GET['id']) : null;
    Vista::render('productos/form', [
        'producto'   => $producto,
        'categorias' => Catalogo::opciones('categorias'),
        'marcas'     => Catalogo::opciones('marcas'),
        'unidades'   => Catalogo::opciones('unidades'),
    ], $producto ? 'Editar producto' : 'Nuevo producto');
    exit;
}

$filtros = [
    'q'            => trim($_GET['q'] ?? ''),
    'categoria_id' => $_GET['categoria_id'] ?? '',
    'marca_id'     => $_GET['marca_id'] ?? '',
    'estado'       => $_GET['estado'] ?? '',
];

// El listado se pagina en pantalla, pero exportar debe llevarse TODO lo que
// cumple el filtro: exportar sólo la página visible confunde a quien espera
// las mismas filas que ve en "Listado de productos (N)".
if ($accion === 'exportar_csv') {
    $filas = Producto::listarTodos($filtros);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="productos_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM: para que Excel abra los acentos bien
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Código', 'Descripción', 'Categoría', 'Marca', 'Und',
        'Stock', 'Mínimo', 'P. Compra', 'P. Venta', 'Estado'], ';');
    foreach ($filas as $p) {
        fputcsv($out, [
            $p['codigo'], $p['descripcion'], $p['categoria'] ?? '', $p['marca'] ?? '', $p['unidad'],
            Vista::num($p['stock_actual']), Vista::num($p['stock_minimo']),
            Vista::num($p['precio_compra']), Vista::num($p['precio_venta']),
            (int) $p['estado'] === 1 ? 'Activo' : 'Inactivo',
        ], ';');
    }
    fclose($out);
    exit;
}

$datos = Producto::listar($filtros, max(1, (int) ($_GET['p'] ?? 1)));

Vista::render('productos/lista', [
    'datos'      => $datos,
    'filtros'    => $filtros,
    'categorias' => Catalogo::opciones('categorias'),
    'marcas'     => Catalogo::opciones('marcas'),
], 'Productos');
