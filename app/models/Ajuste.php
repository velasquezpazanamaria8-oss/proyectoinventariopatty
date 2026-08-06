<?php
/**
 * Ajustes de inventario positivos y negativos. RF-07.
 * El motivo es obligatorio por regla de negocio (RB-05).
 */
class Ajuste
{
    public static function registrar(array $d): int
    {
        $motivo = trim((string) ($d['motivo'] ?? ''));
        if ($motivo === '') {
            throw new InvalidArgumentException('Debe indicar el motivo del ajuste.');
        }
        $tipo = $d['tipo'] === 'NEGATIVO' ? 'NEGATIVO' : 'POSITIVO';
        $empresaId = Empresa::id();

        return DB::transaccion(function () use ($d, $tipo, $motivo, $empresaId) {
            $ajusteId = DB::insertar('ajustes', [
                'empresa_id'  => $empresaId,
                'fecha'       => $d['fecha'],
                'almacen_id'  => (int) $d['almacen_id'],
                'producto_id' => (int) $d['producto_id'],
                'tipo'        => $tipo,
                'cantidad'    => (float) $d['cantidad'],
                'motivo'      => $motivo,
                'usuario_id'  => Auth::id(),
            ]);

            Kardex::registrar([
                'producto_id'    => (int) $d['producto_id'],
                'almacen_id'     => (int) $d['almacen_id'],
                'tipo'           => $tipo === 'POSITIVO' ? Kardex::AJUSTE_POS : Kardex::AJUSTE_NEG,
                'cantidad'       => (float) $d['cantidad'],
                'costo_unitario' => (float) ($d['costo_unitario'] ?? 0),
                'origen_tabla'   => 'ajustes',
                'origen_id'      => $ajusteId,
                'documento'      => 'AJU-' . str_pad((string) $ajusteId, 6, '0', STR_PAD_LEFT),
                'motivo'         => $motivo,
                'fecha'          => $d['fecha'] . ' ' . date('H:i:s'),
            ]);

            Auditoria::registrar('AJUSTE', 'ajustes', $ajusteId, $d);
            return $ajusteId;
        });
    }

    public static function listar(array $f = [], int $limite = 200): array
    {
        $where = [Empresa::filtro('aj')];
        $p = Empresa::param();
        if (!empty($f['desde'])) { $where[] = 'aj.fecha >= :d'; $p[':d'] = $f['desde']; }
        if (!empty($f['hasta'])) { $where[] = 'aj.fecha <= :h'; $p[':h'] = $f['hasta']; }
        if (!empty($f['tipo']))  { $where[] = 'aj.tipo = :t';   $p[':t'] = $f['tipo']; }

        return DB::todos(
            'SELECT aj.*, pr.codigo, pr.descripcion, a.nombre AS almacen, u.usuario
               FROM ajustes aj
               JOIN productos pr ON pr.id = aj.producto_id
               JOIN almacenes a  ON a.id  = aj.almacen_id
               JOIN usuarios  u  ON u.id  = aj.usuario_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY aj.id DESC LIMIT ' . (int) $limite, $p);
    }
}
