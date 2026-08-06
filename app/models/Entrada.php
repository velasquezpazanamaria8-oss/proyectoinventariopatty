<?php
/**
 * Entradas de almacén. RF-05. Acotado a la empresa activa.
 */
class Entrada
{
    public static function listar(array $f = [], int $limite = 200): array
    {
        $where = [Empresa::filtro('e')];
        $p = Empresa::param();
        if (!empty($f['desde']))        { $where[] = 'e.fecha >= :d';        $p[':d']  = $f['desde']; }
        if (!empty($f['hasta']))        { $where[] = 'e.fecha <= :h';        $p[':h']  = $f['hasta']; }
        if (!empty($f['almacen_id']))   { $where[] = 'e.almacen_id = :a';    $p[':a']  = $f['almacen_id']; }
        if (!empty($f['proveedor_id'])) { $where[] = 'e.proveedor_id = :pr'; $p[':pr'] = $f['proveedor_id']; }
        if (!empty($f['q'])) {
            $where[] = '(e.serie_numero LIKE :q1 OR e.nro_documento LIKE :q2)';
            $p[':q1'] = $p[':q2'] = '%' . $f['q'] . '%';
        }

        return DB::todos(
            'SELECT e.*, a.nombre AS almacen, pv.razon_social AS proveedor, u.usuario,
                    (SELECT COUNT(*) FROM entrada_detalle d WHERE d.entrada_id = e.id) AS items
               FROM entradas e
               JOIN almacenes a ON a.id = e.almacen_id
               LEFT JOIN proveedores pv ON pv.id = e.proveedor_id
               JOIN usuarios u ON u.id = e.usuario_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY e.id DESC LIMIT ' . (int) $limite, $p);
    }

    public static function buscar(int $id): ?array
    {
        $e = DB::uno(
            'SELECT e.*, a.nombre AS almacen, pv.razon_social AS proveedor, u.nombres AS usuario_nombre
               FROM entradas e
               JOIN almacenes a ON a.id = e.almacen_id
               LEFT JOIN proveedores pv ON pv.id = e.proveedor_id
               JOIN usuarios u ON u.id = e.usuario_id
              WHERE e.id = :id AND ' . Empresa::filtro('e'),
            Empresa::param() + [':id' => $id]);
        if (!$e) return null;

        $e['detalle'] = DB::todos(
            'SELECT d.*, pr.codigo, pr.descripcion, un.codigo AS unidad
               FROM entrada_detalle d
               JOIN productos pr ON pr.id = d.producto_id
               JOIN unidades un ON un.id = pr.unidad_id
              WHERE d.entrada_id = :id ORDER BY d.id', [':id' => $id]);
        return $e;
    }

    /**
     * Registra la entrada, su detalle, los movimientos de kardex y actualiza stock.
     */
    public static function registrar(array $cab, array $items): int
    {
        if (!$items) {
            throw new InvalidArgumentException('La entrada debe tener al menos un producto.');
        }
        $empresaId = Empresa::id();

        return DB::transaccion(function () use ($cab, $items, $empresaId) {
            $serie = Correlativo::siguiente('E');

            // El proveedor, si se indicó, debe ser de la empresa activa.
            $proveedorId = !empty($cab['proveedor_id']) ? (int) $cab['proveedor_id'] : null;
            if ($proveedorId) {
                $ok = (int) DB::valor('SELECT COUNT(*) FROM proveedores WHERE id = :p AND empresa_id = :e',
                    [':p' => $proveedorId, ':e' => $empresaId]);
                if (!$ok) {
                    throw new RuntimeException('El proveedor no pertenece a la empresa activa.');
                }
            }

            $entradaId = DB::insertar('entradas', [
                'empresa_id'     => $empresaId,
                'serie_numero'   => $serie,
                'fecha'          => $cab['fecha'],
                'almacen_id'     => (int) $cab['almacen_id'],
                'proveedor_id'   => $proveedorId,
                'tipo_documento' => $cab['tipo_documento'] ?: null,
                'nro_documento'  => $cab['nro_documento'] ?: null,
                'observacion'    => $cab['observacion'] ?: null,
                'total'          => 0,
                'estado'         => 'CONFIRMADO',
                'usuario_id'     => Auth::id(),
            ]);

            $total = 0.0;
            foreach ($items as $it) {
                $cantidad = round((float) $it['cantidad'], 4);
                $costo    = round((float) $it['costo_unitario'], 4);
                if ($cantidad <= 0) {
                    throw new InvalidArgumentException('Las cantidades deben ser mayores a cero.');
                }
                $subtotal = round($cantidad * $costo, 4);
                $total   += $subtotal;

                DB::insertar('entrada_detalle', [
                    'entrada_id'     => $entradaId,
                    'producto_id'    => (int) $it['producto_id'],
                    'cantidad'       => $cantidad,
                    'costo_unitario' => $costo,
                    'subtotal'       => $subtotal,
                ]);

                // Kardex valida que el producto y el almacén sean de esta empresa.
                Kardex::registrar([
                    'producto_id'    => (int) $it['producto_id'],
                    'almacen_id'     => (int) $cab['almacen_id'],
                    'tipo'           => Kardex::ENTRADA,
                    'cantidad'       => $cantidad,
                    'costo_unitario' => $costo,
                    'origen_tabla'   => 'entradas',
                    'origen_id'      => $entradaId,
                    'documento'      => $serie,
                    'motivo'         => $cab['observacion'] ?: 'Entrada de almacén',
                    'fecha'          => $cab['fecha'] . ' ' . date('H:i:s'),
                ]);

                DB::actualizar('productos', ['precio_compra' => $costo],
                    'id = :id AND empresa_id = :e', [':id' => (int) $it['producto_id'], ':e' => $empresaId]);
            }

            DB::actualizar('entradas', ['total' => $total], 'id = :id', [':id' => $entradaId]);
            Auditoria::registrar('CREAR', 'entradas', $entradaId, ['serie' => $serie, 'total' => $total, 'items' => count($items)]);

            return $entradaId;
        });
    }
}
