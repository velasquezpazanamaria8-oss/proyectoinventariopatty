<?php
/**
 * Puente entre los reportes y los generadores de PDF y Excel. RF-12, RF-14.
 *
 * Cada reporte se declara una sola vez (columnas, tipos, anchos y alineación)
 * y desde esa definición se produce tanto el .pdf como el .xlsx, de modo que
 * ambos formatos no se desincronizan.
 */
class Exportador
{
    /**
     * Definición de cada reporte.
     * cols: [clave, etiqueta, tipo(texto|numero|fecha), anchoExcel, anchoPdf, alineación]
     */
    public static function definicion(string $reporte): ?array
    {
        $defs = [
            'stock_actual' => [
                'titulo' => 'Stock actual',
                'orientacion' => 'horizontal',
                'permiso' => 'inventario.ver',
                'suma' => ['valor'],
                'cols' => [
                    ['codigo',         'Código',       'texto',  14,  70, 'izq'],
                    ['descripcion',    'Producto',     'texto',  42, 210, 'izq'],
                    ['categoria',      'Categoría',    'texto',  18,  90, 'izq'],
                    ['unidad',         'Und',          'texto',   8,  38, 'centro'],
                    ['fisico',         'Físico',       'numero', 12,  62, 'der'],
                    ['reservado',      'Reservado',    'numero', 12,  62, 'der'],
                    ['disponible',     'Disponible',   'numero', 12,  62, 'der'],
                    ['stock_minimo',   'Mínimo',       'numero', 11,  56, 'der'],
                    ['costo_promedio', 'C. promedio',  'numero', 13,  66, 'der'],
                    ['valor',          'Valorizado',   'numero', 14,  76, 'der'],
                ],
            ],
            'valorizado' => [
                'titulo' => 'Inventario valorizado',
                'orientacion' => 'horizontal',
                'permiso' => 'reportes.valorizado',
                'suma' => ['valor'],
                'cols' => [
                    ['codigo',         'Código',      'texto',  14,  75, 'izq'],
                    ['descripcion',    'Producto',    'texto',  46, 250, 'izq'],
                    ['categoria',      'Categoría',   'texto',  20, 110, 'izq'],
                    ['unidad',         'Und',         'texto',   8,  45, 'centro'],
                    ['fisico',         'Cantidad',    'numero', 13,  75, 'der'],
                    ['costo_promedio', 'C. promedio', 'numero', 14,  85, 'der'],
                    ['valor',          'Valorizado',  'numero', 15,  90, 'der'],
                ],
            ],
            'stock_minimo' => [
                'titulo' => 'Productos con stock mínimo',
                'orientacion' => 'vertical',
                'permiso' => 'reportes.ver',
                'suma' => [],
                'cols' => [
                    ['codigo',       'Código',   'texto',  16,  75, 'izq'],
                    ['descripcion',  'Producto', 'texto',  50, 240, 'izq'],
                    ['unidad',       'Und',      'texto',  10,  45, 'centro'],
                    ['stock_actual', 'Stock',    'numero', 14,  80, 'der'],
                    ['stock_minimo', 'Mínimo',   'numero', 14,  80, 'der'],
                ],
            ],
            'entradas' => [
                'titulo' => 'Entradas por fecha',
                'orientacion' => 'horizontal',
                'permiso' => 'entradas.ver',
                'suma' => ['total'],
                'cols' => [
                    ['serie_numero', 'Serie',     'texto',  14,  75, 'izq'],
                    ['fecha',        'Fecha',     'fecha',  12,  65, 'centro'],
                    ['almacen',      'Almacén',   'texto',  22, 110, 'izq'],
                    ['proveedor',    'Proveedor', 'texto',  32, 175, 'izq'],
                    ['nro_documento','Documento', 'texto',  18,  95, 'izq'],
                    ['items',        'Ítems',     'numero', 10,  50, 'der'],
                    ['total',        'Total',     'numero', 15,  85, 'der'],
                    ['usuario',      'Usuario',   'texto',  16,  85, 'izq'],
                ],
            ],
            'salidas' => [
                'titulo' => 'Salidas por fecha',
                'orientacion' => 'horizontal',
                'permiso' => 'salidas.ver',
                'suma' => ['total'],
                'cols' => [
                    ['serie_numero', 'Serie',   'texto',  14,  75, 'izq'],
                    ['fecha',        'Fecha',   'fecha',  12,  65, 'centro'],
                    ['almacen',      'Almacén', 'texto',  22, 110, 'izq'],
                    ['motivo',       'Motivo',  'texto',  16,  85, 'izq'],
                    ['destino',      'Destino', 'texto',  30, 165, 'izq'],
                    ['items',        'Ítems',   'numero', 10,  50, 'der'],
                    ['total',        'Valor',   'numero', 15,  85, 'der'],
                    ['usuario',      'Usuario', 'texto',  16,  85, 'izq'],
                ],
            ],
            'por_usuario' => [
                'titulo' => 'Movimientos por usuario',
                'orientacion' => 'vertical',
                'permiso' => 'reportes.ver',
                'suma' => ['total'],
                'cols' => [
                    ['usuario',  'Usuario',  'texto',  18,  90, 'izq'],
                    ['nombres',  'Nombres',  'texto',  36, 175, 'izq'],
                    ['entradas', 'Entradas', 'numero', 12,  65, 'der'],
                    ['salidas',  'Salidas',  'numero', 12,  65, 'der'],
                    ['ajustes',  'Ajustes',  'numero', 12,  65, 'der'],
                    ['total',    'Total',    'numero', 12,  65, 'der'],
                ],
            ],
            'por_categoria' => [
                'titulo' => 'Inventario por categoría',
                'orientacion' => 'vertical',
                'permiso' => 'reportes.ver',
                'suma' => ['cantidad', 'valor'],
                'cols' => [
                    ['categoria', 'Categoría', 'texto',  40, 200, 'izq'],
                    ['productos', 'Productos', 'numero', 14,  75, 'der'],
                    ['cantidad',  'Cantidad',  'numero', 16,  90, 'der'],
                    ['valor',     'Valorizado','numero', 18, 100, 'der'],
                ],
            ],
            'por_almacen' => [
                'titulo' => 'Inventario por almacén',
                'orientacion' => 'vertical',
                'permiso' => 'reportes.ver',
                'suma' => ['cantidad', 'valor'],
                'cols' => [
                    ['almacen',   'Almacén',   'texto',  40, 200, 'izq'],
                    ['productos', 'Productos', 'numero', 14,  75, 'der'],
                    ['cantidad',  'Cantidad',  'numero', 16,  90, 'der'],
                    ['valor',     'Valorizado','numero', 18, 100, 'der'],
                ],
            ],
            'kardex_producto' => [
                'titulo' => 'Kardex por producto',
                'orientacion' => 'horizontal',
                'permiso' => 'kardex.ver',
                'suma' => [],
                'cols' => [
                    ['fecha_txt',      'Fecha',       'texto',  17,  85, 'izq'],
                    ['documento',      'Documento',   'texto',  15,  75, 'izq'],
                    ['tipo',           'Tipo',        'texto',  14,  72, 'izq'],
                    ['almacen',        'Almacén',     'texto',  20,  95, 'izq'],
                    ['motivo',         'Motivo',      'texto',  30, 130, 'izq'],
                    ['entrada',        'Entrada',     'numero', 12,  60, 'der'],
                    ['salida',         'Salida',      'numero', 12,  60, 'der'],
                    ['saldo_cantidad', 'Saldo',       'numero', 12,  60, 'der'],
                    ['costo_unitario', 'C. unit.',    'numero', 13,  62, 'der'],
                    ['saldo_costo',    'C. promedio', 'numero', 13,  62, 'der'],
                    ['saldo_valor',    'Valor saldo', 'numero', 14,  74, 'der'],
                ],
            ],
            'kardex_general' => [
                'titulo' => 'Kardex general',
                'orientacion' => 'horizontal',
                'permiso' => 'kardex.ver',
                'suma' => [],
                'cols' => [
                    ['fecha_txt',      'Fecha',     'texto',  17,  85, 'izq'],
                    ['documento',      'Documento', 'texto',  15,  75, 'izq'],
                    ['tipo',           'Tipo',      'texto',  14,  72, 'izq'],
                    ['codigo',         'Código',    'texto',  14,  70, 'izq'],
                    ['descripcion',    'Producto',  'texto',  36, 175, 'izq'],
                    ['almacen',        'Almacén',   'texto',  20,  95, 'izq'],
                    ['cantidad',       'Cantidad',  'numero', 12,  65, 'der'],
                    ['saldo_cantidad', 'Saldo',     'numero', 12,  65, 'der'],
                    ['costo_unitario', 'C. unit.',  'numero', 13,  68, 'der'],
                    ['usuario',        'Usuario',   'texto',  15,  75, 'izq'],
                ],
            ],
            'inventario_fisico' => [
                'titulo' => 'Inventario físico y conciliación',
                'orientacion' => 'horizontal',
                'permiso' => 'inventario.ver',
                'suma' => ['diferencia'],
                'cols' => [
                    ['codigo',        'Código',      'texto',  16,  80, 'izq'],
                    ['descripcion',   'Producto',    'texto',  46, 250, 'izq'],
                    ['unidad',        'Und',         'texto',  10,  50, 'centro'],
                    ['stock_sistema', 'Sistema',     'numero', 14,  85, 'der'],
                    ['stock_fisico',  'Físico',      'numero', 14,  85, 'der'],
                    ['diferencia',    'Diferencia',  'numero', 14,  85, 'der'],
                    ['estado_txt',    'Estado',      'texto',  18,  95, 'izq'],
                ],
            ],
        ];

        return $defs[$reporte] ?? null;
    }

    /** Obtiene los datos del reporte ya normalizados para exportar. */
    public static function datos(string $reporte, array $f): array
    {
        switch ($reporte) {
            case 'stock_actual':
                return Reporte::valorizado($f);

            case 'valorizado':
                return Reporte::valorizado($f);

            case 'stock_minimo':
                return Producto::stockMinimo(!empty($f['almacen_id']) ? (int) $f['almacen_id'] : null);

            case 'entradas':
                return Entrada::listar($f, 5000);

            case 'salidas':
                return Salida::listar($f, 5000);

            case 'por_usuario':
                return Reporte::porUsuario($f['desde'] ?? null, $f['hasta'] ?? null);

            case 'por_categoria':
                return Reporte::porCategoria(!empty($f['almacen_id']) ? (int) $f['almacen_id'] : null);

            case 'por_almacen':
                return Reporte::porAlmacen();

            case 'kardex_producto':
                $movs = Kardex::porProducto(
                    (int) $f['producto_id'],
                    !empty($f['almacen_id']) ? (int) $f['almacen_id'] : null,
                    $f['desde'] ?? null, $f['hasta'] ?? null);
                return array_map(function ($m) {
                    $ingreso = in_array($m['tipo'], ['ENTRADA', 'AJUSTE_POS', 'INV_INICIAL'], true);
                    $m['fecha_txt'] = Vista::fecha($m['fecha'], true);
                    $m['entrada']   = $ingreso ? $m['cantidad'] : '';
                    $m['salida']    = $ingreso ? '' : $m['cantidad'];
                    return $m;
                }, $movs);

            case 'kardex_general':
                return array_map(function ($m) {
                    $m['fecha_txt'] = Vista::fecha($m['fecha'], true);
                    return $m;
                }, Kardex::general($f, 5000));

            case 'inventario_fisico':
                $inv = Inventario::buscar((int) $f['inventario_id']);
                if (!$inv) return [];
                return array_map(function ($d) {
                    $d['estado_txt'] = $d['stock_fisico'] === null
                        ? 'Sin contar'
                        : ((float) $d['diferencia'] == 0.0 ? 'Coincide'
                            : ((float) $d['diferencia'] > 0 ? 'Sobrante' : 'Faltante'));
                    return $d;
                }, $inv['detalle']);
        }
        return [];
    }

    /** Líneas descriptivas de los filtros aplicados. */
    public static function subtitulos(string $reporte, array $f): array
    {
        $s = [];
        if (!empty($f['desde']) || !empty($f['hasta'])) {
            $s[] = 'Periodo: ' . (!empty($f['desde']) ? Vista::fecha($f['desde']) : 'inicio')
                 . ' al ' . (!empty($f['hasta']) ? Vista::fecha($f['hasta']) : 'hoy');
        }
        if (!empty($f['almacen_id'])) {
            $nom = DB::valor('SELECT nombre FROM almacenes WHERE id = :a AND ' . Empresa::filtro(),
                Empresa::param() + [':a' => $f['almacen_id']]);
            if ($nom) $s[] = 'Almacén: ' . $nom;
        }
        if (!empty($f['producto_id'])) {
            $p = Producto::buscar((int) $f['producto_id']);
            if ($p) $s[] = 'Producto: ' . $p['codigo'] . ' — ' . $p['descripcion']
                         . '  (stock actual: ' . Vista::num($p['stock_actual']) . ' ' . $p['unidad'] . ')';
        }
        if (!empty($f['inventario_id'])) {
            $i = Inventario::buscar((int) $f['inventario_id']);
            if ($i) $s[] = 'Conteo ' . $i['codigo'] . ' — ' . $i['almacen']
                         . ' — ' . Vista::fecha($i['fecha']) . ' (' . $i['estado'] . ')';
        }
        if (!empty($f['q'])) {
            $s[] = 'Búsqueda: "' . $f['q'] . '"';
        }
        $s[] = 'Generado por ' . (Auth::usuario()['nombres'] ?? '') . ' el ' . date('d/m/Y H:i');
        return $s;
    }

    // --- Salidas -------------------------------------------------------

    public static function aPdf(string $reporte, array $f): never
    {
        $def   = self::exigirDefinicion($reporte);
        $datos = self::datos($reporte, $f);

        $pdf = new Pdf($def['titulo'], $def['orientacion']);
        foreach (self::subtitulos($reporte, $f) as $s) {
            $pdf->subtitulo($s);
        }
        $pdf->columnas(array_map(fn($c) => [$c[1], $c[4], $c[5]], $def['cols']));

        foreach ($datos as $fila) {
            $pdf->fila(array_map(fn($c) => self::valor($fila, $c, true), $def['cols']));
        }

        if ($def['suma'] && $datos) {
            $pdf->filaTotal(self::filaTotales($def, $datos, true));
        }
        if (!$datos) {
            $pdf->fila(['Sin resultados para los criterios indicados.']);
        }

        $pdf->descargar(self::nombreArchivo($reporte, 'pdf'));
    }

    public static function aExcel(string $reporte, array $f): never
    {
        $def   = self::exigirDefinicion($reporte);
        $datos = self::datos($reporte, $f);

        $x = new Excel($def['titulo']);
        $x->titulo($def['titulo'], Empresa::actual()['razon_social'] . '  ·  RUC ' . Empresa::actual()['ruc']);
        foreach (self::subtitulos($reporte, $f) as $s) {
            $x->linea($s);
        }
        $x->columnas(array_map(fn($c) => [$c[1], $c[3], $c[2]], $def['cols']));

        foreach ($datos as $fila) {
            $x->fila(array_map(fn($c) => self::valor($fila, $c, false), $def['cols']));
        }
        if ($def['suma'] && $datos) {
            $x->filaTotal(self::filaTotales($def, $datos, false));
        }

        $x->descargar(self::nombreArchivo($reporte, 'xlsx'));
    }

    // --- Apoyo ---------------------------------------------------------

    private static function exigirDefinicion(string $reporte): array
    {
        $def = self::definicion($reporte);
        if (!$def) {
            throw new InvalidArgumentException('Reporte desconocido: ' . $reporte);
        }
        Auth::requierePermiso($def['permiso']);
        return $def;
    }

    /**
     * Valor de una celda. En PDF se formatea a texto; en Excel los números
     * se dejan crudos para que la hoja sea calculable.
     */
    private static function valor(array $fila, array $col, bool $paraPdf)
    {
        $v = $fila[$col[0]] ?? '';
        if ($v === null || $v === '') {
            return '';
        }
        if ($col[2] === 'numero') {
            return $paraPdf ? Vista::num($v) : (float) $v;
        }
        if ($col[2] === 'fecha') {
            return $paraPdf ? Vista::fecha($v) : $v;
        }
        return (string) $v;
    }

    private static function filaTotales(array $def, array $datos, bool $paraPdf): array
    {
        $fila = [];
        $puesto = false;
        foreach ($def['cols'] as $c) {
            if (in_array($c[0], $def['suma'], true)) {
                $suma = array_sum(array_map(fn($d) => (float) ($d[$c[0]] ?? 0), $datos));
                $fila[] = $paraPdf ? Vista::num($suma) : $suma;
            } elseif (!$puesto) {
                $fila[]  = 'TOTALES (' . count($datos) . ')';
                $puesto  = true;
            } else {
                $fila[] = '';
            }
        }
        return $fila;
    }

    private static function nombreArchivo(string $reporte, string $ext): string
    {
        $emp = preg_replace('/[^A-Za-z0-9]+/', '_', Empresa::nombre());
        return strtolower($reporte . '_' . $emp . '_' . date('Ymd_His') . '.' . $ext);
    }
}
