<?php
/**
 * Inventario físico y conciliación. RF-11.
 *
 * Flujo:
 *  1. abrir()      — crea el conteo y congela el stock del sistema de cada
 *                    producto del almacén (snapshot `stock_sistema`).
 *  2. guardarConteo() — registra el conteo físico y calcula la diferencia.
 *  3. cerrar()     — genera un ajuste (y su movimiento de kardex) por cada
 *                    diferencia distinta de cero, dejando el stock del sistema
 *                    igual al contado. Todo en una sola transacción.
 *
 * Un conteo cerrado no se puede reabrir: el kardex es inmutable (RB-04).
 */
class Inventario
{
    public static function listar(array $f = [], int $limite = 200): array
    {
        $where = [Empresa::filtro('i')];
        $p = Empresa::param();
        if (!empty($f['almacen_id'])) { $where[] = 'i.almacen_id = :a'; $p[':a'] = $f['almacen_id']; }
        if (!empty($f['estado']))     { $where[] = 'i.estado = :e';     $p[':e'] = $f['estado']; }
        if (!empty($f['desde']))      { $where[] = 'i.fecha >= :d';     $p[':d'] = $f['desde']; }
        if (!empty($f['hasta']))      { $where[] = 'i.fecha <= :h';     $p[':h'] = $f['hasta']; }

        return DB::todos(
            'SELECT i.*, a.nombre AS almacen, u.usuario,
                    (SELECT COUNT(*) FROM inventario_detalle d WHERE d.inventario_id = i.id) AS items,
                    (SELECT COUNT(*) FROM inventario_detalle d
                      WHERE d.inventario_id = i.id AND d.stock_fisico IS NOT NULL) AS contados,
                    (SELECT COUNT(*) FROM inventario_detalle d
                      WHERE d.inventario_id = i.id AND d.diferencia <> 0) AS con_diferencia
               FROM inventarios i
               JOIN almacenes a ON a.id = i.almacen_id
               JOIN usuarios  u ON u.id = i.usuario_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY i.id DESC LIMIT ' . (int) $limite, $p);
    }

    public static function buscar(int $id): ?array
    {
        $inv = DB::uno(
            'SELECT i.*, a.nombre AS almacen, u.nombres AS usuario_nombre
               FROM inventarios i
               JOIN almacenes a ON a.id = i.almacen_id
               JOIN usuarios  u ON u.id = i.usuario_id
              WHERE i.id = :id AND ' . Empresa::filtro('i'),
            Empresa::param() + [':id' => $id]);
        if (!$inv) return null;

        $inv['detalle'] = DB::todos(
            'SELECT d.*, pr.codigo, pr.descripcion, pr.costo_promedio, un.codigo AS unidad
               FROM inventario_detalle d
               JOIN productos pr ON pr.id = d.producto_id
               JOIN unidades un ON un.id = pr.unidad_id
              WHERE d.inventario_id = :id
              ORDER BY pr.descripcion', [':id' => $id]);

        $inv['resumen'] = self::resumen($inv['detalle']);
        return $inv;
    }

    private static function resumen(array $detalle): array
    {
        $r = ['items' => count($detalle), 'contados' => 0, 'sobrantes' => 0,
              'faltantes' => 0, 'coinciden' => 0, 'impacto' => 0.0];

        foreach ($detalle as $d) {
            if ($d['stock_fisico'] === null) continue;
            $r['contados']++;
            $dif = (float) $d['diferencia'];
            if ($dif > 0)      $r['sobrantes']++;
            elseif ($dif < 0)  $r['faltantes']++;
            else               $r['coinciden']++;
            $r['impacto'] += $dif * (float) $d['costo_promedio'];
        }
        $r['pendientes'] = $r['items'] - $r['contados'];
        return $r;
    }

    /**
     * Abre un conteo y congela el stock actual del almacén.
     * @param bool $soloConStock si es false incluye también productos en cero
     */
    public static function abrir(array $d, bool $soloConStock = false): int
    {
        $almacenId = (int) $d['almacen_id'];
        $empresaId = Empresa::id();

        $abierto = DB::valor(
            'SELECT codigo FROM inventarios
              WHERE almacen_id = :a AND estado = \'ABIERTO\' AND ' . Empresa::filtro(),
            Empresa::param() + [':a' => $almacenId]);
        if ($abierto) {
            throw new RuntimeException("Ya existe un conteo abierto para ese almacén ($abierto). Ciérrelo antes de abrir otro.");
        }

        return DB::transaccion(function () use ($d, $almacenId, $empresaId, $soloConStock) {
            $ultimo = (int) DB::valor(
                'SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, 5) AS UNSIGNED)), 0)
                   FROM inventarios WHERE empresa_id = :e AND codigo LIKE \'INV-%\' FOR UPDATE',
                [':e' => $empresaId]);
            $codigo = 'INV-' . str_pad((string) ($ultimo + 1), 5, '0', STR_PAD_LEFT);

            $invId = DB::insertar('inventarios', [
                'empresa_id'  => $empresaId,
                'codigo'      => $codigo,
                'fecha'       => $d['fecha'],
                'almacen_id'  => $almacenId,
                'estado'      => 'ABIERTO',
                'observacion' => trim($d['observacion'] ?? '') ?: null,
                'usuario_id'  => Auth::id(),
            ]);

            // Snapshot del stock en el momento de abrir el conteo.
            // Igual que arriba: se repite la expresión, no el alias.
            $having = $soloConStock ? 'HAVING COALESCE(s.cantidad, 0) <> 0' : '';
            $productos = DB::todos(
                'SELECT pr.id, COALESCE(s.cantidad, 0) AS stock_sistema
                   FROM productos pr
                   LEFT JOIN stock s ON s.producto_id = pr.id AND s.almacen_id = :alm
                  WHERE pr.empresa_id = :e AND pr.estado = 1
                  GROUP BY pr.id, s.cantidad ' . $having,
                [':alm' => $almacenId, ':e' => $empresaId]);

            if (!$productos) {
                throw new RuntimeException('No hay productos activos para inventariar en ese almacén.');
            }

            foreach ($productos as $pr) {
                DB::insertar('inventario_detalle', [
                    'inventario_id' => $invId,
                    'producto_id'   => (int) $pr['id'],
                    'stock_sistema' => (float) $pr['stock_sistema'],
                    'stock_fisico'  => null,
                    'diferencia'    => null,
                    'conciliado'    => 0,
                ]);
            }

            Auditoria::registrar('ABRIR', 'inventarios', $invId,
                ['codigo' => $codigo, 'almacen' => $almacenId, 'items' => count($productos)]);
            return $invId;
        });
    }

    /**
     * Guarda las cantidades contadas. Sólo toca las que llegan en $conteos.
     * @param array $conteos [detalle_id => cantidad|'' ]
     */
    public static function guardarConteo(int $invId, array $conteos): int
    {
        $inv = self::buscar($invId);
        if (!$inv) {
            throw new RuntimeException('El conteo no pertenece a la empresa activa.');
        }
        if ($inv['estado'] !== 'ABIERTO') {
            throw new RuntimeException('El conteo ya está cerrado: no admite cambios.');
        }

        $validos = array_column($inv['detalle'], 'stock_sistema', 'id');
        $tocados = 0;

        DB::transaccion(function () use ($conteos, $validos, $invId, &$tocados) {
            foreach ($conteos as $detId => $cantidad) {
                $detId = (int) $detId;
                if (!array_key_exists($detId, $validos)) {
                    continue;   // no pertenece a este conteo
                }

                if ($cantidad === '' || $cantidad === null) {
                    DB::actualizar('inventario_detalle',
                        ['stock_fisico' => null, 'diferencia' => null],
                        'id = :id AND inventario_id = :i', [':id' => $detId, ':i' => $invId]);
                    $tocados++;
                    continue;
                }

                $fisico = round((float) $cantidad, 4);
                if ($fisico < 0) {
                    throw new InvalidArgumentException('El conteo físico no puede ser negativo.');
                }
                DB::actualizar('inventario_detalle', [
                    'stock_fisico' => $fisico,
                    'diferencia'   => round($fisico - (float) $validos[$detId], 4),
                ], 'id = :id AND inventario_id = :i', [':id' => $detId, ':i' => $invId]);
                $tocados++;
            }
        });

        Auditoria::registrar('CONTAR', 'inventarios', $invId, ['lineas' => $tocados]);
        return $tocados;
    }

    /**
     * Cierra el conteo y concilia: por cada diferencia ≠ 0 genera un ajuste
     * positivo o negativo que deja el stock igual al contado.
     * Las líneas sin contar se ignoran (no se asume que estén en cero).
     *
     * @return array resumen de lo aplicado
     */
    public static function cerrar(int $invId): array
    {
        $inv = self::buscar($invId);
        if (!$inv) {
            throw new RuntimeException('El conteo no pertenece a la empresa activa.');
        }
        if ($inv['estado'] !== 'ABIERTO') {
            throw new RuntimeException('El conteo ya está cerrado.');
        }
        if ($inv['resumen']['contados'] === 0) {
            throw new RuntimeException('No se registró ningún conteo físico: no hay nada que conciliar.');
        }

        return DB::transaccion(function () use ($inv, $invId) {
            $aplicados = 0;
            $sinCambio = 0;

            foreach ($inv['detalle'] as $d) {
                if ($d['stock_fisico'] === null) {
                    continue;
                }
                $dif = round((float) $d['diferencia'], 4);
                if ($dif == 0.0) {
                    $sinCambio++;
                    DB::actualizar('inventario_detalle', ['conciliado' => 1],
                        'id = :id', [':id' => $d['id']]);
                    continue;
                }

                $tipo   = $dif > 0 ? 'POSITIVO' : 'NEGATIVO';
                $motivo = 'Conciliación de inventario físico ' . $inv['codigo']
                        . ' (sistema ' . rtrim(rtrim(number_format((float) $d['stock_sistema'], 4, '.', ''), '0'), '.')
                        . ' → contado ' . rtrim(rtrim(number_format((float) $d['stock_fisico'], 4, '.', ''), '0'), '.') . ')';

                $ajusteId = DB::insertar('ajustes', [
                    'empresa_id'  => Empresa::id(),
                    'fecha'       => $inv['fecha'],
                    'almacen_id'  => (int) $inv['almacen_id'],
                    'producto_id' => (int) $d['producto_id'],
                    'tipo'        => $tipo,
                    'cantidad'    => abs($dif),
                    'motivo'      => $motivo,
                    'usuario_id'  => Auth::id(),
                ]);

                Kardex::registrar([
                    'producto_id'  => (int) $d['producto_id'],
                    'almacen_id'   => (int) $inv['almacen_id'],
                    'tipo'         => $dif > 0 ? Kardex::AJUSTE_POS : Kardex::AJUSTE_NEG,
                    'cantidad'     => abs($dif),
                    'origen_tabla' => 'ajustes',
                    'origen_id'    => $ajusteId,
                    'documento'    => $inv['codigo'],
                    'motivo'       => $motivo,
                    'fecha'        => $inv['fecha'] . ' ' . date('H:i:s'),
                ]);

                DB::actualizar('inventario_detalle', ['conciliado' => 1], 'id = :id', [':id' => $d['id']]);
                $aplicados++;
            }

            DB::actualizar('inventarios', [
                'estado'     => 'CERRADO',
                'cerrado_en' => date('Y-m-d H:i:s'),
            ], 'id = :id AND ' . Empresa::filtro(), Empresa::param() + [':id' => $invId]);

            Auditoria::registrar('CERRAR', 'inventarios', $invId, [
                'codigo' => $inv['codigo'], 'ajustes' => $aplicados, 'sin_cambio' => $sinCambio,
            ]);

            return ['ajustes' => $aplicados, 'sin_cambio' => $sinCambio,
                    'pendientes' => $inv['resumen']['pendientes']];
        });
    }

    /** Anula un conteo abierto sin tocar el stock. */
    public static function anular(int $invId): void
    {
        $inv = self::buscar($invId);
        if (!$inv) {
            throw new RuntimeException('El conteo no pertenece a la empresa activa.');
        }
        if ($inv['estado'] !== 'ABIERTO') {
            throw new RuntimeException('Sólo se puede anular un conteo abierto.');
        }
        DB::eliminar('inventarios', 'id = :id AND ' . Empresa::filtro(),
            Empresa::param() + [':id' => $invId]);
        Auditoria::registrar('ANULAR', 'inventarios', $invId, ['codigo' => $inv['codigo']]);
    }
}
