<?php
/**
 * Comprobantes traídos del SIRE, guardados por empresa y período.
 */
class SunatComprobante
{
    /**
     * Inserta o actualiza un lote. La clave natural es
     * (empresa, período, tipo, serie, número): volver a sincronizar el mismo
     * período actualiza en vez de duplicar.
     *
     * @return int filas afectadas
     */
    public static function guardarLote(string $periodo, string $tipo, array $filas): int
    {
        if (!$filas) {
            return 0;
        }
        $empresaId = Empresa::id();

        return DB::transaccion(function () use ($filas, $periodo, $tipo, $empresaId) {
            $n = 0;
            foreach ($filas as $f) {
                $datos = [
                    'empresa_id'         => $empresaId,
                    'periodo'            => $periodo,
                    'tipo'               => $tipo,
                    'cod_tipo_cdp'       => $f['cod_tipo_cdp'],
                    'serie'              => $f['serie'],
                    'numero'             => (string) $f['numero'],
                    'fecha_emision'      => $f['fecha_emision'],
                    'ruc_contraparte'    => $f['ruc_contraparte'],
                    'nombre_contraparte' => $f['nombre_contraparte'],
                    'base_gravada'       => $f['base_gravada'],
                    'igv'                => $f['igv'],
                    'total'              => $f['total'],
                    'moneda'             => $f['moneda'],
                    'estado_sunat'       => $f['estado_sunat'],
                    'payload'            => json_encode($f['payload'], JSON_UNESCAPED_UNICODE),
                    'sincronizado_en'    => date('Y-m-d H:i:s'),
                ];

                $cols = array_keys($datos);
                $ph   = array_map(fn($c) => ':' . $c, $cols);
                // Se actualiza todo menos la clave natural.
                $sets = [];
                foreach ($cols as $c) {
                    if (in_array($c, ['empresa_id', 'periodo', 'tipo', 'serie', 'numero'], true)) continue;
                    $sets[] = "$c = VALUES($c)";
                }

                DB::query(
                    'INSERT INTO sunat_comprobantes (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ') '
                    . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $sets),
                    array_combine($ph, array_values($datos)));
                $n++;
            }
            return $n;
        });
    }

    /** Cuántos comprobantes hay realmente guardados de un período y tipo. */
    public static function contar(string $periodo, string $tipo): int
    {
        return (int) DB::valor(
            'SELECT COUNT(*) FROM sunat_comprobantes
              WHERE ' . Empresa::filtro() . ' AND periodo = :per AND tipo = :t',
            Empresa::param() + [':per' => $periodo, ':t' => $tipo]);
    }

    /** Listado con filtros para la pantalla. */
    public static function listar(array $f = [], int $limite = 500): array
    {
        $where = [Empresa::filtro('sc')];
        $p = Empresa::param();

        if (!empty($f['periodo'])) { $where[] = 'sc.periodo = :per'; $p[':per'] = preg_replace('/\D/', '', $f['periodo']); }
        if (!empty($f['tipo']))    { $where[] = 'sc.tipo = :t';      $p[':t']   = $f['tipo']; }
        if (!empty($f['cod']))     { $where[] = 'sc.cod_tipo_cdp = :c'; $p[':c'] = $f['cod']; }
        if (!empty($f['q'])) {
            $where[] = '(sc.serie LIKE :q1 OR sc.numero LIKE :q2 OR sc.nombre_contraparte LIKE :q3 OR sc.ruc_contraparte LIKE :q4)';
            $t = '%' . $f['q'] . '%';
            $p[':q1'] = $t; $p[':q2'] = $t; $p[':q3'] = $t; $p[':q4'] = $t;
        }

        return DB::todos(
            'SELECT sc.* FROM sunat_comprobantes sc
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY sc.fecha_emision DESC, sc.serie, CAST(sc.numero AS UNSIGNED) DESC
              LIMIT ' . (int) $limite, $p);
    }

    /** Totales de lo guardado, para contrastar con SUNAT. */
    public static function totales(array $f = []): array
    {
        $where = [Empresa::filtro('sc')];
        $p = Empresa::param();
        if (!empty($f['periodo'])) { $where[] = 'sc.periodo = :per'; $p[':per'] = preg_replace('/\D/', '', $f['periodo']); }
        if (!empty($f['tipo']))    { $where[] = 'sc.tipo = :t';      $p[':t']   = $f['tipo']; }

        $r = DB::uno(
            'SELECT COUNT(*) AS documentos,
                    COALESCE(SUM(base_gravada),0) AS base,
                    COALESCE(SUM(igv),0)          AS igv,
                    COALESCE(SUM(total),0)        AS total
               FROM sunat_comprobantes sc WHERE ' . implode(' AND ', $where), $p);

        return [
            'documentos' => (int) $r['documentos'],
            'base'  => (float) $r['base'],
            'igv'   => (float) $r['igv'],
            'total' => (float) $r['total'],
        ];
    }

    /** Períodos ya sincronizados, con cuántos comprobantes tiene cada uno. */
    public static function periodosSincronizados(): array
    {
        $filas = DB::todos(
            'SELECT periodo,
                    SUM(tipo = \'ventas\')  AS ventas,
                    SUM(tipo = \'compras\') AS compras,
                    MAX(sincronizado_en)    AS ultima,
                    SUM(mov_id IS NOT NULL) AS generados
               FROM sunat_comprobantes
              WHERE ' . Empresa::filtro() . '
              GROUP BY periodo ORDER BY periodo DESC', Empresa::param());

        $out = [];
        foreach ($filas as $f) {
            $out[$f['periodo']] = $f;
        }
        return $out;
    }

    /**
     * Borra todo lo traído de un período (ventas y compras), con sus ítems
     * (por la FK ON DELETE CASCADE de sunat_cpe_items).
     *
     * Se niega si algún comprobante de ese período ya generó un movimiento de
     * inventario (mov_id): borrarlo ahí perdería el enlace, y al volver a
     * sincronizar la fase 4 lo tomaría por nuevo y duplicaría el stock. No
     * toca entradas/salidas ya creadas —esas quedan intactas, sólo se pierde
     * el rastro hacia el comprobante de origen— por eso el candado es antes,
     * no después.
     */
    public static function eliminarPeriodo(string $periodo, bool $conMovimientos = false): int
    {
        $empresaId = Empresa::id();
        $generados = (int) DB::valor(
            'SELECT COUNT(*) FROM sunat_comprobantes
              WHERE empresa_id = :e AND periodo = :per AND mov_id IS NOT NULL',
            [':e' => $empresaId, ':per' => $periodo]);

        if ($generados > 0) {
            if (!$conMovimientos) {
                throw new RuntimeException(
                    "No se puede eliminar: $generados comprobante(s) de este período ya generaron "
                    . 'un movimiento de inventario (entrada o salida). Marque "incluir movimientos '
                    . 'ya generados" si de verdad quiere deshacerlos también.');
            }
            self::deshacerMovimientosDelPeriodo($periodo);
        }

        return DB::transaccion(function () use ($empresaId, $periodo) {
            DB::eliminar('sunat_descargas_activas', 'empresa_id = :e AND periodo = :per',
                [':e' => $empresaId, ':per' => $periodo]);
            return DB::eliminar('sunat_comprobantes', 'empresa_id = :e AND periodo = :per',
                [':e' => $empresaId, ':per' => $periodo]);
        });
    }

    /**
     * Deshace, sólo para este período, las entradas/salidas que la fase 4
     * generó a partir de sus comprobantes: borra esos movimientos del kardex,
     * su entrada/salida, y deja el stock y el costo promedio como si nunca
     * hubieran existido. Los demás períodos (kardex, stock, costos) no se
     * tocan más que para recalcularse con lo que queda.
     *
     * El kardex es de sólo-añadir: cada fila guarda el saldo del momento, así
     * que borrar unas cuantas de la mitad deja los saldos de las de después
     * desactualizados. Por eso, tras borrar, se llama a
     * Kardex::recalcularSaldos() -que reproduce el kardex que QUEDA, en orden
     * cronológico- y se vuelve a fijar el stock desde la última fila real de
     * cada producto. Con PEPS/UEPS ese recálculo no es seguro (dependen de
     * capas, no de una suma corrida), así que ahí se rechaza de entrada.
     */
    private static function deshacerMovimientosDelPeriodo(string $periodo): void
    {
        $empresaId = Empresa::id();

        if (Valorizacion::usaCapas()) {
            throw new RuntimeException(
                'Esta empresa valoriza por ' . Valorizacion::metodo() . ', que depende de capas de '
                . 'costo: no se puede deshacer sólo un período sin reconstruirlas una por una. '
                . 'Use "Deshacer todo" en Generar movimientos (deshace TODOS los períodos) y vuelva '
                . 'a generar los que sí quiere conservar.');
        }

        $movs = DB::todos(
            'SELECT DISTINCT mov_tabla, mov_id FROM sunat_comprobantes
              WHERE empresa_id = :e AND periodo = :per AND mov_id IS NOT NULL',
            [':e' => $empresaId, ':per' => $periodo]);
        if (!$movs) {
            return;
        }

        // Si alguna de estas salidas ya quedó enlazada desde una cotización
        // aceptada, borrarla rompería ese enlace: se avisa en vez de arrasar.
        $idsSalida = array_column(array_filter($movs, fn($m) => $m['mov_tabla'] === 'salidas'), 'mov_id');
        if ($idsSalida) {
            $enUso = DB::valor(
                'SELECT COUNT(*) FROM cotizaciones WHERE ' . Empresa::filtro()
                . ' AND salida_id IN (' . implode(',', array_map('intval', $idsSalida)) . ')',
                Empresa::param());
            if ($enUso > 0) {
                throw new RuntimeException(
                    "$enUso salida(s) de este período están enlazadas desde una cotización aceptada. "
                    . 'No se puede deshacer sin romper ese enlace. Revise esas cotizaciones primero.');
            }
        }

        // El borrado va en su propia transacción; el recálculo que sigue abre
        // las suyas (Kardex::recalcularSaldos() entre otras) y este motor no
        // admite transacciones anidadas.
        $afectados = DB::transaccion(function () use ($movs, $empresaId) {
            $afectados = []; // "producto_id-almacen_id" => [producto_id, almacen_id]

            foreach ($movs as $m) {
                $tabla = $m['mov_tabla'];
                $id    = (int) $m['mov_id'];
                $detalle = $tabla === 'entradas' ? 'entrada_detalle' : 'salida_detalle';
                $col     = $tabla === 'entradas' ? 'entrada_id' : 'salida_id';

                $filasKardex = DB::todos(
                    'SELECT id, producto_id, almacen_id FROM kardex
                      WHERE empresa_id = :e AND origen_tabla = :t AND origen_id = :id',
                    [':e' => $empresaId, ':t' => $tabla, ':id' => $id]);
                foreach ($filasKardex as $k) {
                    $afectados[$k['producto_id'] . '-' . $k['almacen_id']] = [$k['producto_id'], $k['almacen_id']];
                }

                $idsKardex = array_column($filasKardex, 'id');
                if ($idsKardex) {
                    $in = implode(',', array_map('intval', $idsKardex));
                    DB::query("DELETE FROM kardex_capa WHERE kardex_id IN ($in)");
                    DB::query("DELETE FROM kardex WHERE id IN ($in)");
                }

                DB::query("DELETE FROM $detalle WHERE $col = :id", [':id' => $id]);
                DB::eliminar($tabla, 'id = :id AND ' . Empresa::filtro(), Empresa::param() + [':id' => $id]);
            }

            return $afectados;
        });

        if ($afectados) {
            // Reproduce el kardex que QUEDA, en orden cronológico, para que
            // los saldos de los períodos posteriores vuelvan a progresar bien.
            Kardex::recalcularSaldos();

            // El stock/costo vigente de cada producto+almacén tocado es lo que
            // diga su última fila de kardex real; sin ninguna, vuelve a cero.
            foreach ($afectados as [$productoId, $almacenId]) {
                $ultima = DB::uno(
                    'SELECT saldo_cantidad, saldo_costo FROM kardex
                      WHERE producto_id = :p AND almacen_id = :a
                      ORDER BY fecha DESC, id DESC LIMIT 1',
                    [':p' => $productoId, ':a' => $almacenId]);

                DB::actualizar('stock', [
                    'cantidad'       => $ultima ? $ultima['saldo_cantidad'] : 0,
                    'costo_promedio' => $ultima ? $ultima['saldo_costo'] : 0,
                ], 'producto_id = :p AND almacen_id = :a', [':p' => $productoId, ':a' => $almacenId]);

                $costo = Valorizacion::recalcularCostoGlobal($productoId);

                // Con ámbito GLOBAL todos los almacenes del producto comparten
                // un solo costo (así lo deja Kardex::registrar()); si sólo se
                // corrige el almacén tocado, los demás quedan con el costo
                // viejo y dejan de cuadrar con productos.costo_promedio.
                if (Valorizacion::ambito() === Valorizacion::AMBITO_GLOBAL) {
                    DB::query('UPDATE stock SET costo_promedio = :c WHERE producto_id = :p',
                        [':c' => $costo, ':p' => $productoId]);
                }
            }
        }

        Auditoria::registrar('SUNAT_PERIODO_MOVIMIENTOS_DESHECHOS', 'sunat_comprobantes', null,
            ['periodo' => $periodo, 'movimientos' => count($movs)]);
    }

    /** Etiqueta legible del tipo de documento. */
    public static function tipoDoc(?string $cod): string
    {
        return [
            '01' => 'Factura', '03' => 'Boleta', '07' => 'Nota de crédito',
            '08' => 'Nota de débito', '12' => 'Ticket', '14' => 'Recibo servicios',
        ][$cod] ?? ('Tipo ' . ($cod ?? '?'));
    }
}
