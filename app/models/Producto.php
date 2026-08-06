<?php
/**
 * Gestión de productos. RF-03, RF-09, RF-10, RF-13.
 * Todas las consultas están acotadas a la empresa activa.
 */
class Producto
{
    public static function listar(array $f = [], int $pagina = 1, int $porPagina = 20): array
    {
        [$where, $p] = self::filtros($f);

        $total = (int) DB::valor(
            'SELECT COUNT(*) FROM productos pr
               LEFT JOIN categorias c ON c.id = pr.categoria_id
               LEFT JOIN marcas     m ON m.id = pr.marca_id
              WHERE ' . $where, $p);

        $offset = max(0, ($pagina - 1) * $porPagina);
        $filas = DB::todos(
            'SELECT pr.*, c.nombre AS categoria, m.nombre AS marca, un.codigo AS unidad,
                    COALESCE(s.total, 0) AS stock_actual,
                    COALESCE(s.reservado, 0) AS reservado
               FROM productos pr
               LEFT JOIN categorias c  ON c.id  = pr.categoria_id
               LEFT JOIN marcas     m  ON m.id  = pr.marca_id
               JOIN      unidades   un ON un.id = pr.unidad_id
               LEFT JOIN (SELECT producto_id, SUM(cantidad) AS total, SUM(reservado) AS reservado
                            FROM stock GROUP BY producto_id) s ON s.producto_id = pr.id
              WHERE ' . $where . '
              ORDER BY pr.descripcion ASC
              LIMIT ' . (int) $porPagina . ' OFFSET ' . (int) $offset, $p);

        return ['filas' => $filas, 'total' => $total, 'paginas' => (int) ceil($total / $porPagina), 'pagina' => $pagina];
    }

    private static function filtros(array $f): array
    {
        $where = [Empresa::filtro('pr')];
        $p = Empresa::param();
        if (!empty($f['q'])) {
            // Con prepares nativos un placeholder no se puede repetir:
            // cada ocurrencia lleva su propio nombre.
            $where[] = '(pr.codigo LIKE :q1 OR pr.descripcion LIKE :q2 OR c.nombre LIKE :q3 OR m.nombre LIKE :q4)';
            $t = '%' . $f['q'] . '%';
            $p[':q1'] = $t; $p[':q2'] = $t; $p[':q3'] = $t; $p[':q4'] = $t;
        }
        if (!empty($f['categoria_id'])) { $where[] = 'pr.categoria_id = :cat'; $p[':cat'] = $f['categoria_id']; }
        if (!empty($f['marca_id']))     { $where[] = 'pr.marca_id = :mar';     $p[':mar'] = $f['marca_id']; }
        if (isset($f['estado']) && $f['estado'] !== '') { $where[] = 'pr.estado = :est'; $p[':est'] = (int) $f['estado']; }
        return [implode(' AND ', $where), $p];
    }

    public static function buscar(int $id): ?array
    {
        return DB::uno(
            'SELECT pr.*, c.nombre AS categoria, m.nombre AS marca, un.codigo AS unidad,
                    COALESCE((SELECT SUM(cantidad) FROM stock WHERE producto_id = pr.id), 0) AS stock_actual
               FROM productos pr
               LEFT JOIN categorias c  ON c.id  = pr.categoria_id
               LEFT JOIN marcas     m  ON m.id  = pr.marca_id
               JOIN      unidades   un ON un.id = pr.unidad_id
              WHERE pr.id = :id AND ' . Empresa::filtro('pr'),
            Empresa::param() + [':id' => $id]);
    }

    public static function porCodigo(string $codigo): ?array
    {
        return DB::uno(
            'SELECT * FROM productos WHERE codigo = :c AND ' . Empresa::filtro(),
            Empresa::param() + [':c' => $codigo]);
    }

    /** Autocompletado con stock por almacén. RF-13. */
    public static function autocompletar(string $termino, int $almacenId, int $limite = 15): array
    {
        return DB::todos(
            'SELECT pr.id, pr.codigo, pr.descripcion, pr.precio_compra, pr.costo_promedio,
                    un.codigo AS unidad, COALESCE(s.cantidad, 0) AS stock
               FROM productos pr
               JOIN unidades un ON un.id = pr.unidad_id
               LEFT JOIN stock s ON s.producto_id = pr.id AND s.almacen_id = :alm
              WHERE ' . Empresa::filtro('pr') . '
                AND pr.estado = 1 AND (pr.codigo LIKE :t1 OR pr.descripcion LIKE :t2)
              ORDER BY pr.descripcion LIMIT ' . (int) $limite,
            Empresa::param() + [
                ':t1'  => '%' . $termino . '%',
                ':t2'  => '%' . $termino . '%',
                ':alm' => $almacenId,
            ]);
    }

    /**
     * Buscador paginado para la ventana de selección de productos.
     * A diferencia de autocompletar(), admite término vacío (lista todo) y
     * devuelve datos de catálogo para poder decidir sin salir de la ventana.
     */
    public static function buscador(string $q, int $almacenId, int $pagina = 1,
                                    int $porPagina = 12, ?int $categoriaId = null): array
    {
        $where = [Empresa::filtro('pr'), 'pr.estado = 1'];
        $p = Empresa::param() + [':alm' => $almacenId];

        if ($q !== '') {
            $where[] = '(pr.codigo LIKE :q1 OR pr.descripcion LIKE :q2 OR c.nombre LIKE :q3 OR m.nombre LIKE :q4)';
            $t = '%' . $q . '%';
            $p[':q1'] = $t; $p[':q2'] = $t; $p[':q3'] = $t; $p[':q4'] = $t;
        }
        if ($categoriaId) {
            $where[] = 'pr.categoria_id = :cat';
            $p[':cat'] = $categoriaId;
        }
        $sqlWhere = implode(' AND ', $where);

        $total = (int) DB::valor(
            'SELECT COUNT(*) FROM productos pr
               LEFT JOIN categorias c ON c.id = pr.categoria_id
               LEFT JOIN marcas     m ON m.id = pr.marca_id
              WHERE ' . $sqlWhere,
            array_diff_key($p, [':alm' => null]));

        $pagina = max(1, $pagina);
        $offset = ($pagina - 1) * $porPagina;

        $filas = DB::todos(
            'SELECT pr.id, pr.codigo, pr.descripcion, pr.precio_compra, pr.precio_venta,
                    pr.costo_promedio, pr.stock_minimo,
                    un.codigo AS unidad, c.nombre AS categoria, m.nombre AS marca,
                    COALESCE(s.cantidad, 0) AS stock
               FROM productos pr
               JOIN unidades un ON un.id = pr.unidad_id
               LEFT JOIN categorias c ON c.id = pr.categoria_id
               LEFT JOIN marcas     m ON m.id = pr.marca_id
               LEFT JOIN stock      s ON s.producto_id = pr.id AND s.almacen_id = :alm
              WHERE ' . $sqlWhere . '
              ORDER BY pr.descripcion
              LIMIT ' . (int) $porPagina . ' OFFSET ' . (int) $offset, $p);

        return [
            'filas'   => $filas,
            'total'   => $total,
            'pagina'  => $pagina,
            'paginas' => max(1, (int) ceil($total / $porPagina)),
        ];
    }

    public static function guardar(array $d, ?int $id = null): int
    {
        $datos = [
            'codigo'        => trim($d['codigo']),
            'descripcion'   => trim($d['descripcion']),
            'categoria_id'  => $d['categoria_id'] !== '' ? (int) $d['categoria_id'] : null,
            'marca_id'      => $d['marca_id'] !== '' ? (int) $d['marca_id'] : null,
            'unidad_id'     => (int) $d['unidad_id'],
            'precio_compra' => (float) ($d['precio_compra'] ?? 0),
            'precio_venta'  => (float) ($d['precio_venta'] ?? 0),
            'stock_minimo'  => (float) ($d['stock_minimo'] ?? 0),
            'estado'        => isset($d['estado']) ? (int) $d['estado'] : 1,
        ];

        // Categoría, marca y unidad deben ser de la misma empresa.
        self::validarReferencias($datos);

        if ($id === null) {
            $nuevoId = DB::insertar('productos', Empresa::sello($datos));
            Auditoria::registrar('CREAR', 'productos', $nuevoId, $datos);
            return $nuevoId;
        }

        $anterior = self::buscar($id);
        if (!$anterior) {
            throw new RuntimeException('El producto no pertenece a la empresa activa.');
        }
        DB::actualizar('productos', $datos, 'id = :id AND ' . Empresa::filtro(),
            Empresa::param() + [':id' => $id]);
        Auditoria::registrar('EDITAR', 'productos', $id, ['antes' => $anterior, 'despues' => $datos]);
        return $id;
    }

    /** Impide referenciar catálogos de otra empresa. */
    private static function validarReferencias(array $d): void
    {
        $refs = [
            'categorias' => $d['categoria_id'],
            'marcas'     => $d['marca_id'],
            'unidades'   => $d['unidad_id'],
        ];
        foreach ($refs as $tabla => $valor) {
            if (!$valor) continue;
            $existe = (int) DB::valor(
                "SELECT COUNT(*) FROM $tabla WHERE id = :id AND empresa_id = :e",
                [':id' => $valor, ':e' => Empresa::id()]);
            if (!$existe) {
                throw new RuntimeException("El registro seleccionado en $tabla no pertenece a la empresa activa.");
            }
        }
    }

    /** No se elimina físicamente si tiene movimientos: se desactiva (RB-04). */
    public static function eliminar(int $id): string
    {
        if (!self::buscar($id)) {
            throw new RuntimeException('El producto no pertenece a la empresa activa.');
        }
        $tieneMovimientos = (int) DB::valor('SELECT COUNT(*) FROM kardex WHERE producto_id = :p', [':p' => $id]) > 0;
        if ($tieneMovimientos) {
            DB::actualizar('productos', ['estado' => 0], 'id = :id AND ' . Empresa::filtro(),
                Empresa::param() + [':id' => $id]);
            Auditoria::registrar('DESACTIVAR', 'productos', $id, 'Tiene movimientos en kardex');
            return 'El producto tiene movimientos registrados: se desactivó en lugar de eliminarse.';
        }
        DB::eliminar('stock', 'producto_id = :p', [':p' => $id]);
        DB::eliminar('productos', 'id = :id AND ' . Empresa::filtro(), Empresa::param() + [':id' => $id]);
        Auditoria::registrar('ELIMINAR', 'productos', $id, 'Eliminación definitiva');
        return 'Producto eliminado correctamente.';
    }

    /** Productos en o bajo el stock mínimo. RF-10. */
    public static function stockMinimo(?int $almacenId = null): array
    {
        $p = Empresa::param();
        $filtroAlm = '';
        if ($almacenId) { $filtroAlm = ' AND s.almacen_id = :alm'; $p[':alm'] = $almacenId; }

        return DB::todos(
            'SELECT pr.id, pr.codigo, pr.descripcion, pr.stock_minimo, un.codigo AS unidad,
                    COALESCE(SUM(s.cantidad), 0) AS stock_actual
               FROM productos pr
               JOIN unidades un ON un.id = pr.unidad_id
               LEFT JOIN stock s ON s.producto_id = pr.id' . $filtroAlm . '
              WHERE ' . Empresa::filtro('pr') . ' AND pr.estado = 1
              GROUP BY pr.id, pr.codigo, pr.descripcion, pr.stock_minimo, un.codigo
             -- Se repite la expresión en lugar de usar el alias: MariaDB no
             -- admite referenciar una función de agregación por su alias.
             HAVING COALESCE(SUM(s.cantidad), 0) <= pr.stock_minimo
              ORDER BY (COALESCE(SUM(s.cantidad), 0) - pr.stock_minimo) ASC, pr.descripcion', $p);
    }
}
