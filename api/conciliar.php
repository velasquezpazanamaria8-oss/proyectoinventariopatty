<?php
/** Guarda la decisión de conciliación de un ítem y devuelve cómo quedó. */
require_once __DIR__ . '/../bootstrap.php';

if (!Auth::autenticado() || !Auth::puede('sunat.gestionar')) {
    Vista::json(['ok' => false, 'error' => 'Sin permisos'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::valido($_POST['_csrf'] ?? null)) {
    Vista::json(['ok' => false, 'error' => 'Petición no válida'], 400);
}

$accion = $_POST['accion'] ?? '';
$ruc    = (string) ($_POST['origen_ruc'] ?? '');
$clave  = (string) ($_POST['clave'] ?? '');
$desc   = (string) ($_POST['descripcion'] ?? '');

if ($clave === '') {
    Vista::json(['ok' => false, 'error' => 'Falta la clave del ítem'], 400);
}

try {
    switch ($accion) {
        case 'emparejar':
            $productoId = (int) ($_POST['producto_id'] ?? 0);
            if (!$productoId) {
                throw new InvalidArgumentException('No se indicó el producto.');
            }
            Conciliacion::decidir($ruc, $clave, $productoId, false, $desc);
            $p = Producto::buscar($productoId);
            Vista::json(['ok' => true, 'estado' => 'mapeado',
                'producto' => $p['codigo'] . ' — ' . $p['descripcion']]);

        // Datos con los que proponer el formulario de alta.
        case 'sugerir_alta':
            Vista::json(['ok' => true, 'valores' => Conciliacion::valoresSugeridos([
                'codigo_sunat'  => $_POST['codigo_sunat'] ?? '',
                'descripcion'   => $desc,
                'unidad_codigo' => $_POST['unidad_codigo'] ?? '',
                'unidad_nombre' => $_POST['unidad_nombre'] ?? '',
                'precio_min'    => $_POST['precio'] ?? 0,
                'tipo'          => $_POST['tipo'] ?? '',
            ], $ruc, $clave)]);

        // Alta con los datos que el usuario confirmó en el formulario.
        case 'crear':
            $productoId = Conciliacion::crearProductoCon([
                'codigo'        => $_POST['codigo'] ?? '',
                'descripcion'   => $_POST['descripcion_producto'] ?? $desc,
                'categoria_id'  => $_POST['categoria_id'] ?? '',
                'marca_id'      => $_POST['marca_id'] ?? '',
                'unidad_id'     => $_POST['unidad_id'] ?? 0,
                'precio_compra' => $_POST['precio_compra'] ?? 0,
                'precio_venta'  => $_POST['precio_venta'] ?? 0,
                'stock_minimo'  => $_POST['stock_minimo'] ?? 0,
            ], $ruc, $clave);
            $p = Producto::buscar($productoId);
            Vista::json(['ok' => true, 'estado' => 'creado',
                'producto' => $p['codigo'] . ' — ' . $p['descripcion']]);

        // Comprobantes donde aparece el ítem, para poder mirar el PDF.
        case 'comprobantes':
            $lista = Conciliacion::comprobantesDe($ruc, $clave, [
                'periodo' => $_POST['periodo'] ?? '',
                'tipo'    => $_POST['tipo_filtro'] ?? '',
            ]);
            Vista::json(['ok' => true, 'comprobantes' => array_map(fn($c) => [
                'id'          => (int) $c['id'],
                'tipo'        => $c['tipo'],
                'documento'   => $c['serie'] . '-' . $c['numero'],
                'tipo_doc'    => SunatComprobante::tipoDoc($c['cod_tipo_cdp']),
                'fecha'       => Vista::fecha($c['fecha_emision']),
                'contraparte' => $c['nombre_contraparte'] ?? '',
                'cantidad'    => (float) $c['cantidad'],
                'valor'       => (float) $c['valor_unitario'],
                'descripcion' => $c['descripcion'],
                'tiene_pdf'   => (bool) $c['tiene_pdf'],
                'tiene_xml'   => (bool) $c['tiene_xml'],
                'tiene_cdr'   => (bool) $c['tiene_cdr'],
            ], $lista)]);

        case 'ignorar':
            Conciliacion::decidir($ruc, $clave, null, true, $desc);
            Vista::json(['ok' => true, 'estado' => 'ignorado']);

        case 'deshacer':
            Conciliacion::decidir($ruc, $clave, null, false, $desc);
            Vista::json(['ok' => true, 'estado' => 'pendiente']);

        default:
            Vista::json(['ok' => false, 'error' => 'Acción desconocida'], 400);
    }
} catch (Throwable $e) {
    Vista::json(['ok' => false, 'error' => $e->getMessage()], 500);
}
