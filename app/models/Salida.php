<?php
/**
 * Salidas de almacén. RF-06. Valida stock antes de confirmar (RB-01).
 * Acotado a la empresa activa.
 */
class Salida
{
    public const MOTIVOS = ['VENTA', 'CONSUMO', 'MERMA', 'TRASLADO', 'DEVOLUCION', 'OTROS'];

    public static function listar(array $f = [], int $limite = 200): array
    {
        $where = [Empresa::filtro('s')];
        $p = Empresa::param();
        if (!empty($f['desde']))      { $where[] = 's.fecha >= :d';     $p[':d'] = $f['desde']; }
        if (!empty($f['hasta']))      { $where[] = 's.fecha <= :h';     $p[':h'] = $f['hasta']; }
        if (!empty($f['almacen_id'])) { $where[] = 's.almacen_id = :a'; $p[':a'] = $f['almacen_id']; }
        if (!empty($f['motivo']))     { $where[] = 's.motivo = :m';     $p[':m'] = $f['motivo']; }
        if (!empty($f['q'])) {
            $where[] = '(s.serie_numero LIKE :q1 OR s.destino LIKE :q2)';
            $p[':q1'] = $p[':q2'] = '%' . $f['q'] . '%';
        }

        return DB::todos(
            'SELECT s.*, a.nombre AS almacen, u.usuario,
                    (SELECT COUNT(*) FROM salida_detalle d WHERE d.salida_id = s.id) AS items
               FROM salidas s
               JOIN almacenes a ON a.id = s.almacen_id
               JOIN usuarios  u ON u.id = s.usuario_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY s.id DESC LIMIT ' . (int) $limite, $p);
    }

    public static function buscar(int $id): ?array
    {
        $s = DB::uno(
            'SELECT s.*, a.nombre AS almacen, u.nombres AS usuario_nombre
               FROM salidas s
               JOIN almacenes a ON a.id = s.almacen_id
               JOIN usuarios  u ON u.id = s.usuario_id
              WHERE s.id = :id AND ' . Empresa::filtro('s'),
            Empresa::param() + [':id' => $id]);
        if (!$s) return null;

        $s['detalle'] = DB::todos(
            'SELECT d.*, pr.codigo, pr.descripcion, un.codigo AS unidad
               FROM salida_detalle d
               JOIN productos pr ON pr.id = d.producto_id
               JOIN unidades un ON un.id = pr.unidad_id
              WHERE d.salida_id = :id ORDER BY d.id', [':id' => $id]);
        return $s;
    }

    public static function registrar(array $cab, array $items): int
    {
        if (!$items) {
            throw new InvalidArgumentException('La salida debe tener al menos un producto.');
        }
        $empresaId = Empresa::id();

        return DB::transaccion(function () use ($cab, $items, $empresaId) {
            $serie = Correlativo::siguiente('S');

            $salidaId = DB::insertar('salidas', [
                'empresa_id'   => $empresaId,
                'serie_numero' => $serie,
                'fecha'        => $cab['fecha'],
                'almacen_id'   => (int) $cab['almacen_id'],
                'motivo'       => $cab['motivo'],
                'destino'      => $cab['destino'] ?: null,
                'observacion'  => $cab['observacion'] ?: null,
                'total'        => 0,
                'estado'       => 'CONFIRMADO',
                'usuario_id'   => Auth::id(),
            ]);

            $total = 0.0;
            foreach ($items as $it) {
                $productoId = (int) $it['producto_id'];
                $cantidad   = round((float) $it['cantidad'], 4);
                if ($cantidad <= 0) {
                    throw new InvalidArgumentException('Las cantidades deben ser mayores a cero.');
                }

                // Kardex valida stock (RB-01) y pertenencia a la empresa.
                $kardexId = Kardex::registrar([
                    'producto_id'  => $productoId,
                    'almacen_id'   => (int) $cab['almacen_id'],
                    'tipo'         => Kardex::SALIDA,
                    'cantidad'     => $cantidad,
                    'origen_tabla' => 'salidas',
                    'origen_id'    => $salidaId,
                    'documento'    => $serie,
                    'motivo'       => $cab['motivo'] . ($cab['destino'] ? ' - ' . $cab['destino'] : ''),
                    'fecha'        => $cab['fecha'] . ' ' . date('H:i:s'),
                ]);

                // El costo lo fija el método de valorización al registrar el
                // movimiento: con PEPS/UEPS es el de las capas consumidas, no
                // el promedio del producto.
                $costo = (float) DB::valor(
                    'SELECT costo_unitario FROM kardex WHERE id = :k', [':k' => $kardexId]);

                // Importe exacto: con PEPS/UEPS es la suma de las capas
                // consumidas, no la cantidad por el costo unitario redondeado.
                $subtotal = Valorizacion::valorMovimiento($kardexId, $cantidad, $costo);
                $total   += $subtotal;

                DB::insertar('salida_detalle', [
                    'salida_id'      => $salidaId,
                    'producto_id'    => $productoId,
                    'cantidad'       => $cantidad,
                    'costo_unitario' => $costo,
                    'subtotal'       => $subtotal,
                ]);
            }

            DB::actualizar('salidas', ['total' => $total], 'id = :id', [':id' => $salidaId]);
            Auditoria::registrar('CREAR', 'salidas', $salidaId, ['serie' => $serie, 'total' => $total, 'items' => count($items)]);

            return $salidaId;
        });
    }
}
