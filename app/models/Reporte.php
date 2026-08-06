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
