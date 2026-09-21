<?php
/**
 * Consultas para los reportes del punto 8 del documento.
 * Todo acotado a la empresa activa.
 */
class Reporte
{
    /** Stock actual: físico, reservado y disponible. RF-09. */
    public static function stockActual(array $f = []): array
    {
        $where = [Empresa::filtro('pr'), 'pr.estado = 1'];
        $p = Empresa::param();
        if (!empty($f['almacen_id']))   { $where[] = 's.almacen_id = :a';    $p[':a'] = $f['almacen_id']; }
        if (!empty($f['categoria_id'])) { $where[] = 'pr.categoria_id = :c'; $p[':c'] = $f['categoria_id']; }
        if (!empty($f['q'])) {
            $where[] = '(pr.codigo LIKE :q1 OR pr.descripcion LIKE :q2)';
            $p[':q1'] = $p[':q2'] = '%' . $f['q'] . '%';
        }
        // 'codigo' es el mismo filtro con otro nombre: lo usan los reportes
        // (Reportes > Inventario valorizado), mientras 'q' lo usa la pantalla
        // de Stock actual. Se aceptan los dos para no duplicar el método.
        if (!empty($f['codigo'])) {
            $where[] = 'pr.codigo LIKE :cod';
            $p[':cod'] = $f['codigo'] . '%';
        }

        return DB::todos(
            'SELECT pr.id, pr.codigo, pr.descripcion, pr.stock_minimo, pr.costo_promedio,
                    un.codigo AS unidad, c.nombre AS categoria, m.nombre AS marca,
                    COALESCE(SUM(s.cantidad), 0)  AS fisico,
                    COALESCE(SUM(s.reservado), 0) AS reservado,
                    COALESCE(SUM(s.cantidad), 0) - COALESCE(SUM(s.reservado), 0) AS disponible
               FROM productos pr
               JOIN unidades un ON un.id = pr.unidad_id
               LEFT JOIN categorias c ON c.id = pr.categoria_id
               LEFT JOIN marcas     m ON m.id = pr.marca_id
               LEFT JOIN stock      s ON s.producto_id = pr.id
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY pr.id, pr.codigo, pr.descripcion, pr.stock_minimo, pr.costo_promedio,
                       un.codigo, c.nombre, m.nombre
              ORDER BY pr.descripcion', $p);
    }

    /** Inventario valorizado (cantidad × costo promedio). */
    public static function valorizado(array $f = []): array
    {
        $filas = self::stockActual($f);
        foreach ($filas as &$r) {
            $r['valor'] = round((float) $r['fisico'] * (float) $r['costo_promedio'], 2);
        }
        unset($r);

        // El estado (normal / stock mínimo / agotado) sale de comparar
        // disponible contra el mínimo, no de una columna guardada: se filtra
        // aquí, después de calcularlo, para que la pantalla y la exportación
        // (PDF/Excel/CSV) usen el mismo criterio sin duplicarlo.
        if (!empty($f['estado'])) {
            $filas = array_values(array_filter($filas, function ($r) use ($f) {
                $disp = (float) $r['disponible'];
                $min  = (float) $r['stock_minimo'];
                $estado = $disp <= 0 ? 'agotado' : ($disp <= $min ? 'minimo' : 'normal');
                return $estado === $f['estado'];
            }));
        }

        return $filas;
    }

    public static function totalValorizado(array $f = []): float
    {
        return array_sum(array_column(self::valorizado($f), 'valor'));
    }

    /** Inventario agrupado por categoría. */
    public static function porCategoria(?int $almacenId = null): array
    {
        $p = Empresa::param();
        $filtro = '';
        if ($almacenId) { $filtro = ' AND s.almacen_id = :a'; $p[':a'] = $almacenId; }

        return DB::todos(
            // Literales en comillas simples: con ANSI_QUOTES activo, las
            // comillas dobles se interpretarían como nombre de columna.
            'SELECT COALESCE(c.nombre, \'SIN CATEGORÍA\') AS categoria,
                    COUNT(DISTINCT pr.id) AS productos,
                    COALESCE(SUM(s.cantidad), 0) AS cantidad,
                    COALESCE(SUM(s.cantidad * pr.costo_promedio), 0) AS valor
               FROM productos pr
               LEFT JOIN categorias c ON c.id = pr.categoria_id
               LEFT JOIN stock s ON s.producto_id = pr.id' . $filtro . '
              WHERE ' . Empresa::filtro('pr') . ' AND pr.estado = 1
              GROUP BY categoria ORDER BY valor DESC', $p);
    }

    /** Inventario agrupado por almacén (de la empresa activa). */
    public static function porAlmacen(): array
    {
        return DB::todos(
            'SELECT a.nombre AS almacen,
                    COUNT(DISTINCT s.producto_id) AS productos,
                    COALESCE(SUM(s.cantidad), 0) AS cantidad,
                    COALESCE(SUM(s.cantidad * pr.costo_promedio), 0) AS valor
               FROM almacenes a
               LEFT JOIN stock s ON s.almacen_id = a.id
               LEFT JOIN productos pr ON pr.id = s.producto_id
              WHERE ' . Empresa::filtro('a') . ' AND a.estado = 1
              GROUP BY a.id, a.nombre ORDER BY a.nombre', Empresa::param());
    }

    /** Movimientos agrupados por usuario. */
    public static function porUsuario(?string $desde = null, ?string $hasta = null): array
    {
        $where = [Empresa::filtro('k')];
        $p = Empresa::param();
        if ($desde) { $where[] = 'k.fecha >= :d'; $p[':d'] = $desde . ' 00:00:00'; }
        if ($hasta) { $where[] = 'k.fecha <= :h'; $p[':h'] = $hasta . ' 23:59:59'; }

        return DB::todos(
            'SELECT u.usuario, u.nombres,
                    SUM(k.tipo = \'ENTRADA\') AS entradas,
                    SUM(k.tipo = \'SALIDA\')  AS salidas,
                    SUM(k.tipo IN (\'AJUSTE_POS\',\'AJUSTE_NEG\')) AS ajustes,
                    COUNT(*) AS total
               FROM kardex k
               JOIN usuarios u ON u.id = k.usuario_id
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY u.id, u.usuario, u.nombres
              ORDER BY total DESC', $p);
    }

    /**
     * Compras (entradas) agrupadas por producto, en un rango de fechas.
     * Para "un mes": desde = primer día del mes, hasta = último. Para "un
     * día": desde = hasta = esa fecha. El mismo filtro sirve para ambos.
     */
    public static function comprasPorProducto(array $f = []): array
    {
        $where = [Empresa::filtro('e'), "e.estado != 'ANULADO'"];
        $p = Empresa::param();
        if (!empty($f['desde']))      { $where[] = 'e.fecha >= :d';      $p[':d'] = $f['desde']; }
        if (!empty($f['hasta']))      { $where[] = 'e.fecha <= :h';      $p[':h'] = $f['hasta']; }
        if (!empty($f['almacen_id'])) { $where[] = 'e.almacen_id = :a';  $p[':a'] = $f['almacen_id']; }

        // Con un código puesto, no interesa el resumen: interesa cada día que
        // se compró ese producto puntual, uno por fila (lo que pidió el
        // contador: "qué días compró tal producto y cuánto", no el total).
        if (!empty($f['codigo'])) {
            $where[] = 'pr.codigo LIKE :cod';
            $p[':cod'] = $f['codigo'] . '%';

            return DB::todos(
                'SELECT e.fecha, e.serie_numero AS documento, pr.codigo, pr.descripcion,
                        un.codigo AS unidad, prov.razon_social AS proveedor,
                        d.cantidad, d.costo_unitario, d.subtotal AS total
                   FROM entrada_detalle d
                   JOIN entradas    e    ON e.id  = d.entrada_id
                   JOIN productos   pr   ON pr.id = d.producto_id
                   JOIN unidades    un   ON un.id = pr.unidad_id
                   LEFT JOIN proveedores prov ON prov.id = e.proveedor_id
                  WHERE ' . implode(' AND ', $where) . '
                  ORDER BY e.fecha, e.id', $p);
        }

        return DB::todos(
            'SELECT pr.id, pr.codigo, pr.descripcion, un.codigo AS unidad,
                    COUNT(DISTINCT d.entrada_id)                              AS documentos,
                    COALESCE(SUM(d.cantidad), 0)                              AS cantidad,
                    COALESCE(SUM(d.subtotal), 0)                              AS total,
                    CASE WHEN SUM(d.cantidad) > 0
                         THEN SUM(d.subtotal) / SUM(d.cantidad) ELSE 0 END    AS costo_promedio
               FROM entrada_detalle d
               JOIN entradas  e  ON e.id  = d.entrada_id
               JOIN productos pr ON pr.id = d.producto_id
               JOIN unidades  un ON un.id = pr.unidad_id
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY pr.id, pr.codigo, pr.descripcion, un.codigo
              ORDER BY total DESC', $p);
    }

    /** Ventas (salidas) agrupadas por producto, en un rango de fechas. */
    public static function ventasPorProducto(array $f = []): array
    {
        $where = [Empresa::filtro('s'), "s.estado != 'ANULADO'"];
        $p = Empresa::param();
        if (!empty($f['desde']))      { $where[] = 's.fecha >= :d';      $p[':d'] = $f['desde']; }
        if (!empty($f['hasta']))      { $where[] = 's.fecha <= :h';      $p[':h'] = $f['hasta']; }
        if (!empty($f['almacen_id'])) { $where[] = 's.almacen_id = :a';  $p[':a'] = $f['almacen_id']; }

        if (!empty($f['codigo'])) {
            $where[] = 'pr.codigo LIKE :cod';
            $p[':cod'] = $f['codigo'] . '%';

            return DB::todos(
                'SELECT s.fecha, s.serie_numero AS documento, pr.codigo, pr.descripcion,
                        un.codigo AS unidad, s.destino AS cliente,
                        d.cantidad, d.costo_unitario, d.subtotal AS total
                   FROM salida_detalle d
                   JOIN salidas   s  ON s.id  = d.salida_id
                   JOIN productos pr ON pr.id = d.producto_id
                   JOIN unidades  un ON un.id = pr.unidad_id
                  WHERE ' . implode(' AND ', $where) . '
                  ORDER BY s.fecha, s.id', $p);
        }

        return DB::todos(
            'SELECT pr.id, pr.codigo, pr.descripcion, un.codigo AS unidad,
                    COUNT(DISTINCT d.salida_id)                                AS documentos,
                    COALESCE(SUM(d.cantidad), 0)                               AS cantidad,
                    COALESCE(SUM(d.subtotal), 0)                               AS total,
                    CASE WHEN SUM(d.cantidad) > 0
                         THEN SUM(d.subtotal) / SUM(d.cantidad) ELSE 0 END     AS costo_promedio
               FROM salida_detalle d
               JOIN salidas   s  ON s.id  = d.salida_id
               JOIN productos pr ON pr.id = d.producto_id
               JOIN unidades  un ON un.id = pr.unidad_id
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY pr.id, pr.codigo, pr.descripcion, un.codigo
              ORDER BY total DESC', $p);
    }

    /** Indicadores del panel principal. */
    public static function resumen(): array
    {
        $hoy = date('Y-m-d');
        $emp = Empresa::param();

        return [
            'productos'    => (int) DB::valor(
                'SELECT COUNT(*) FROM productos WHERE ' . Empresa::filtro() . ' AND estado = 1', $emp),
            'stock_minimo' => count(Producto::stockMinimo()),
            'agotados'     => (int) DB::valor(
                'SELECT COUNT(*) FROM productos pr
                  WHERE ' . Empresa::filtro('pr') . ' AND pr.estado = 1
                    AND COALESCE((SELECT SUM(cantidad) FROM stock WHERE producto_id = pr.id), 0) <= 0', $emp),
            'entradas_hoy' => (int) DB::valor(
                'SELECT COUNT(*) FROM entradas WHERE ' . Empresa::filtro() . ' AND fecha = :f',
                $emp + [':f' => $hoy]),
            'salidas_hoy'  => (int) DB::valor(
                'SELECT COUNT(*) FROM salidas WHERE ' . Empresa::filtro() . ' AND fecha = :f',
                $emp + [':f' => $hoy]),
            'valor_total'  => (float) DB::valor(
                'SELECT COALESCE(SUM(s.cantidad * pr.costo_promedio), 0)
                   FROM stock s JOIN productos pr ON pr.id = s.producto_id
                  WHERE ' . Empresa::filtro('pr'), $emp),
        ];
    }
}
