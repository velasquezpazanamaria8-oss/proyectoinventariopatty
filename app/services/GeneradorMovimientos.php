<?php
/**
 * Convierte los comprobantes de SUNAT en movimientos reales de inventario.
 *
 * Reglas que gobiernan la conversión:
 *
 *   compra  factura/ND  → ENTRADA   (suma stock, al costo sin IGV del comprobante)
 *   compra  nota crédito→ SALIDA    (devolución al proveedor: resta)
 *   venta   factura/ND  → SALIDA    (resta stock, valorizada por el método de la empresa)
 *   venta   nota crédito→ ENTRADA   (devolución del cliente: vuelve al almacén)
 *
 * Tres cosas no negociables:
 *
 *  1. ORDEN CRONOLÓGICO estricto, mezclando compras y ventas. El kardex graba
 *     el saldo al momento de registrar: procesar todas las compras y luego
 *     todas las ventas dejaría una columna de saldos incoherente.
 *  2. IDEMPOTENCIA. Cada comprobante guarda qué movimiento generó; volver a
 *     ejecutar no duplica.
 *  3. UN COMPROBANTE POR TRANSACCIÓN. Si uno falla (stock insuficiente), se
 *     anota el motivo y se sigue con el resto, en vez de abortar todo.
 */
class GeneradorMovimientos
{
    /** Sólo se convierten los documentos que mueven mercadería. */
    private const TIPOS = ['01', '03', '07', '08'];   // factura, boleta, NC, ND

    /**
     * Revisa qué pasaría antes de tocar nada: cuántos se generarían, qué falta
     * conciliar y qué comprobantes quedarían fuera.
     */
    public static function revisar(array $f = []): array
    {
        // Se resuelve con agregados en SQL: hacerlo fila por fila costaba dos
        // consultas por comprobante en CADA carga de la pantalla, y con unos
        // miles de comprobantes la página no llegaba a pintarse.
        [$where, $p] = self::filtroPorGenerar($f);
        $tipos = "'" . implode("','", self::TIPOS) . "'";

        $filas = DB::todos(
            "SELECT c.id, c.tipo, c.cod_tipo_cdp, c.fecha_emision,
                    -- Mismos criterios que lineas() y lineasSinMapear(). El
                    -- `i.id IS NOT NULL` es del LEFT JOIN: sin él, un
                    -- comprobante sin ninguna línea contaría como una línea
                    -- sin conciliar.
                    SUM(i.id IS NOT NULL AND i.producto_id IS NOT NULL AND i.cantidad > 0) AS utiles,
                    SUM(i.id IS NOT NULL AND i.producto_id IS NULL)                        AS sin_mapear
               FROM sunat_comprobantes c
               LEFT JOIN sunat_cpe_items i ON i.cpe_id = c.id
              WHERE " . implode(' AND ', $where) . "
                AND c.cod_tipo_cdp IN ($tipos)
                AND c.descarga_estado = 'OK'
              GROUP BY c.id, c.tipo, c.cod_tipo_cdp, c.fecha_emision", $p);

        $r = [
            'total'        => count($filas),
            'entradas'     => 0,
            'salidas'      => 0,
            'sin_lineas'   => 0,
            'sin_conciliar'=> 0,
            'lineas'       => 0,
            'primera'      => null,
            'ultima'       => null,
        ];

        foreach ($filas as $c) {
            if ((int) $c['sin_mapear'] > 0) $r['sin_conciliar']++;

            $utiles = (int) $c['utiles'];
            if (!$utiles) { $r['sin_lineas']++; continue; }

            $r['lineas'] += $utiles;
            self::esIngreso($c) ? $r['entradas']++ : $r['salidas']++;

            $r['primera'] = $r['primera'] === null ? $c['fecha_emision'] : min($r['primera'], $c['fecha_emision']);
            $r['ultima']  = $r['ultima']  === null ? $c['fecha_emision'] : max($r['ultima'],  $c['fecha_emision']);
        }

        $r['ya_generados'] = (int) DB::valor(
            'SELECT COUNT(*) FROM sunat_comprobantes WHERE ' . Empresa::filtro() . ' AND mov_id IS NOT NULL',
            Empresa::param());

        return $r;
    }

    /**
     * Aplica el stock inicial capturado como movimientos INV_INICIAL, fechados
     * el día ANTES del primer comprobante. Sin esto, las ventas de productos
     * comprados antes del período fallarían por falta de stock.
     */
    public static function aplicarStockInicial(int $almacenId, ?string $fecha = null): array
    {
        if (!Catalogo::buscar('almacenes', $almacenId)) {
            throw new RuntimeException('El almacén no pertenece a la empresa activa.');
        }

        $pendientes = DB::todos(
            'SELECT si.*, pr.codigo, pr.descripcion
               FROM sunat_stock_inicial si
               JOIN productos pr ON pr.id = si.producto_id
              WHERE ' . Empresa::filtro('si') . ' AND si.almacen_id = :a
                AND si.aplicado_en IS NULL AND si.cantidad > 0',
            Empresa::param() + [':a' => $almacenId]);

        if (!$pendientes) {
            return ['aplicados' => 0, 'fecha' => null];
        }

        if ($fecha === null) {
            $primera = DB::valor(
                'SELECT MIN(fecha_emision) FROM sunat_comprobantes WHERE ' . Empresa::filtro(),
                Empresa::param());
            $fecha = $primera
                ? date('Y-m-d', strtotime($primera . ' -1 day'))
                : date('Y-m-d');
        }

        $n = 0;
        $omitidos = [];

        foreach ($pendientes as $p) {
            // Un saldo inicial fechado ANTES de movimientos que ya existen
            // rompería el kardex: cada fila guarda el saldo del momento en que
            // se insertó, así que al leerlo por fecha los saldos no cuadrarían.
            // Si el producto ya se movió, hay que corregirlo con un ajuste.
            $yaTieneMovimientos = (int) DB::valor(
                'SELECT COUNT(*) FROM kardex WHERE producto_id = :p AND almacen_id = :a',
                [':p' => $p['producto_id'], ':a' => $almacenId]) > 0;

            if ($yaTieneMovimientos) {
                $omitidos[] = $p['codigo'] . ' — ' . mb_substr((string) $p['descripcion'], 0, 40);
                continue;
            }

            DB::transaccion(function () use ($p, $almacenId, $fecha, &$n) {
                Kardex::registrar([
                    'producto_id'    => (int) $p['producto_id'],
                    'almacen_id'     => $almacenId,
                    'tipo'           => Kardex::INV_INICIAL,
                    'cantidad'       => (float) $p['cantidad'],
                    'costo_unitario' => (float) $p['costo_unitario'],
                    'origen_tabla'   => 'sunat_stock_inicial',
                    'origen_id'      => (int) $p['id'],
                    'documento'      => 'SALDO-INICIAL',
                    'motivo'         => 'Saldo inicial antes de la importación de SUNAT',
                    'fecha'          => $fecha . ' 00:00:00',
                ]);
                DB::actualizar('sunat_stock_inicial', ['aplicado_en' => date('Y-m-d H:i:s')],
                    'id = :id', [':id' => $p['id']]);
                $n++;
            });
        }

        Auditoria::registrar('STOCK_INICIAL_APLICADO', 'sunat_stock_inicial', null,
            ['productos' => $n, 'fecha' => $fecha, 'omitidos' => count($omitidos)]);

        return ['aplicados' => $n, 'fecha' => $fecha, 'omitidos' => $omitidos];
    }

    /**
     * Deshace TODO lo que esta pantalla generó: el saldo inicial y los
     * comprobantes convertidos. Deja el inventario en cero y los comprobantes
     * listos para volver a convertirse.
     *
     * Existe porque el kardex es de sólo añadir —cada fila guarda el saldo del
     * momento— y no se puede corregir una carga inicial mal valorizada
     * insertando algo encima: habría que recalcular todos los saldos
     * posteriores. Volver a empezar es lo único que deja el kardex coherente.
     *
     * NO se toca nada de lo anterior a esta fase: catálogo, comprobantes,
     * líneas, equivalencias y archivos siguen intactos. Sólo desaparecen los
     * movimientos.
     *
     * @return array recuento de lo borrado
     */
    public static function deshacerTodo(): array
    {
        // Candado: si hay un movimiento que no salió de aquí —un ajuste, un
        // inventario físico, una entrada tecleada a mano— borrar arrasaría con
        // trabajo que esta pantalla no puede rehacer.
        $ajenos = (int) DB::valor(
            'SELECT COUNT(*) FROM kardex k
              WHERE ' . Empresa::filtro('k') . '
                AND NOT (
                  k.origen_tabla = \'sunat_stock_inicial\'
                  OR (k.origen_tabla = \'entradas\' AND EXISTS (
                        SELECT 1 FROM sunat_comprobantes c
                         WHERE c.mov_tabla = \'entradas\' AND c.mov_id = k.origen_id))
                  OR (k.origen_tabla = \'salidas\' AND EXISTS (
                        SELECT 1 FROM sunat_comprobantes c
                         WHERE c.mov_tabla = \'salidas\' AND c.mov_id = k.origen_id))
                )', Empresa::param());

        if ($ajenos > 0) {
            throw new RuntimeException(
                "Hay $ajenos movimiento(s) en el kardex que no salieron de la importación de "
                . 'SUNAT (ajustes, inventarios o movimientos registrados a mano). Deshacer '
                . 'borraría ese trabajo, así que no se hace nada. Corrija la valorización con '
                . 'un ajuste en vez de rehacer la carga.');
        }

        $r = ['kardex' => 0, 'entradas' => 0, 'salidas' => 0, 'comprobantes' => 0, 'inicial' => 0];

        DB::transaccion(function () use (&$r) {
            $p = Empresa::param();

            $r['kardex'] = (int) DB::valor('SELECT COUNT(*) FROM kardex WHERE ' . Empresa::filtro(), $p);

            // Los consumos de capas cuelgan del kardex; las capas, de la empresa.
            DB::query('DELETE kc FROM kardex_capa kc
                         JOIN kardex k ON k.id = kc.kardex_id
                        WHERE ' . Empresa::filtro('k'), $p);
            DB::query('DELETE FROM kardex WHERE ' . Empresa::filtro(), $p);
            DB::query('DELETE FROM capas_costo WHERE ' . Empresa::filtro(), $p);

            // Las entradas y salidas creadas al convertir. El detalle se va en
            // cascada, pero se borra explícito para no depender de ello.
            $r['entradas'] = (int) DB::valor('SELECT COUNT(*) FROM entradas WHERE ' . Empresa::filtro(), $p);
            $r['salidas']  = (int) DB::valor('SELECT COUNT(*) FROM salidas  WHERE ' . Empresa::filtro(), $p);
            DB::query('DELETE d FROM entrada_detalle d JOIN entradas e ON e.id = d.entrada_id
                        WHERE ' . Empresa::filtro('e'), $p);
            DB::query('DELETE d FROM salida_detalle d JOIN salidas s ON s.id = d.salida_id
                        WHERE ' . Empresa::filtro('s'), $p);
            DB::query('DELETE FROM entradas WHERE ' . Empresa::filtro(), $p);
            DB::query('DELETE FROM salidas  WHERE ' . Empresa::filtro(), $p);

            // El inventario vuelve a cero: sin movimientos no hay existencias
            // ni costo que sostener.
            DB::query('UPDATE stock s JOIN productos pr ON pr.id = s.producto_id
                          SET s.cantidad = 0, s.costo_promedio = 0
                        WHERE ' . Empresa::filtro('pr'), $p);
            DB::query('UPDATE productos SET costo_promedio = 0 WHERE ' . Empresa::filtro(), $p);

            // Los comprobantes quedan como antes de convertirse.
            $r['comprobantes'] = DB::query(
                'UPDATE sunat_comprobantes
                    SET mov_id = NULL, mov_tabla = NULL, mov_msg = NULL, generado_en = NULL
                  WHERE ' . Empresa::filtro() . ' AND (mov_id IS NOT NULL OR mov_msg IS NOT NULL)',
                $p)->rowCount();

            // Y el saldo inicial vuelve a estar pendiente, con sus cantidades:
            // sólo hay que revisarle el costo antes de volver a aplicarlo.
            $r['inicial'] = DB::query(
                'UPDATE sunat_stock_inicial SET aplicado_en = NULL
                  WHERE ' . Empresa::filtro() . ' AND aplicado_en IS NOT NULL', $p)->rowCount();
        });

        Auditoria::registrar('MOVIMIENTOS_DESHECHOS', 'kardex', null, $r);
        return $r;
    }

    /**
     * Genera los movimientos de un lote de comprobantes, en orden cronológico.
     * @return array resultado por comprobante
     */
    public static function generar(int $almacenId, array $f = [], int $limite = 20): array
    {
        if (!Catalogo::buscar('almacenes', $almacenId)) {
            throw new RuntimeException('El almacén no pertenece a la empresa activa.');
        }

        // El saldo inicial va SIEMPRE primero. Si se aplicara después —fechado
        // antes de movimientos ya registrados— el kardex quedaría con saldos
        // que no cuadran al leerlo por fecha. Se comprobó en pruebas.
        $pendiente = (int) DB::valor(
            'SELECT COUNT(*) FROM sunat_stock_inicial
              WHERE ' . Empresa::filtro() . ' AND almacen_id = :a
                AND aplicado_en IS NULL AND cantidad > 0',
            Empresa::param() + [':a' => $almacenId]);

        if ($pendiente > 0) {
            throw new RuntimeException(
                "Hay $pendiente producto(s) con saldo inicial sin aplicar. Aplíquelo antes de "
                . 'convertir comprobantes: si se aplica después, el kardex queda con saldos '
                . 'incoherentes porque el saldo inicial va fechado antes de estos movimientos.');
        }

        $hechos = [];

        foreach (self::porGenerar($f, $limite) as $c) {
            $lineas = self::lineas((int) $c['id']);
            $doc = $c['serie'] . '-' . $c['numero'];

            if (!$lineas) {
                // Sin líneas conciliadas no hay nada que mover. No es un error:
                // puede ser un comprobante de servicios.
                self::anotar((int) $c['id'], null, null,
                    'Sin líneas de inventario (¿servicios o falta conciliar?)');
                $hechos[] = ['doc' => $doc, 'ok' => false, 'msg' => 'sin líneas conciliadas'];
                continue;
            }

            try {
                $ingreso = self::esIngreso($c);
                $id = $ingreso
                    ? self::crearEntrada($c, $lineas, $almacenId)
                    : self::crearSalida($c, $lineas, $almacenId);

                self::anotar((int) $c['id'], $ingreso ? 'entradas' : 'salidas', $id, null);
                $hechos[] = ['doc' => $doc, 'ok' => true,
                             'tipo' => $ingreso ? 'entrada' : 'salida', 'lineas' => count($lineas)];

            } catch (Throwable $e) {
                // El fallo típico es stock insuficiente: se anota con su motivo
                // y se sigue, para no detener toda la importación por uno.
                self::anotar((int) $c['id'], null, null, $e->getMessage());
                $hechos[] = ['doc' => $doc, 'ok' => false, 'msg' => $e->getMessage()];
            }
        }
        return $hechos;
    }

    // ------------------------------------------------------------------

    /** ¿Este comprobante suma stock? */
    private static function esIngreso(array $c): bool
    {
        $esNota = ($c['cod_tipo_cdp'] ?? '') === '07';       // nota de crédito
        // Compra normal suma; compra devuelta resta. Venta resta; venta
        // devuelta suma. Es decir: la nota de crédito invierte el sentido.
        return $c['tipo'] === 'compras' ? !$esNota : $esNota;
    }

    /** WHERE y parámetros comunes de "lo que queda por generar". */
    private static function filtroPorGenerar(array $f): array
    {
        $where = [Empresa::filtro('c'), 'c.mov_id IS NULL'];
        $p = Empresa::param();

        if (!empty($f['periodo'])) { $where[] = 'c.periodo = :per'; $p[':per'] = preg_replace('/\D/', '', $f['periodo']); }
        if (!empty($f['desde']))   { $where[] = 'c.fecha_emision >= :d'; $p[':d'] = $f['desde']; }

        return [$where, $p];
    }

    /** Comprobantes por generar, en orden cronológico estricto. */
    private static function porGenerar(array $f, int $limite): array
    {
        [$where, $p] = self::filtroPorGenerar($f);

        $tipos = "'" . implode("','", self::TIPOS) . "'";

        // Los que ya fallaron van AL FINAL. Si no, un puñado de comprobantes
        // con stock insuficiente se queda a la cabeza del orden cronológico y
        // cada lote reprocesa exactamente los mismos: el resto no se convierte
        // nunca. Se reintentan igual, pero sin bloquear la cola.
        //
        // Dentro de un mismo día mandan las COMPRAS. SUNAT sólo entrega la
        // fecha, no la hora, así que el orden dentro del día habría que
        // adivinarlo; y la mercadería tiene que existir antes de venderse. Sin
        // esta regla, una venta del día 7 cubierta por una compra del día 7
        // falla por «stock insuficiente», se reintenta más tarde y acaba
        // grabada fuera de orden, dejando la columna de saldos sin sentido.
        return DB::todos(
            "SELECT c.* FROM sunat_comprobantes c
              WHERE " . implode(' AND ', $where) . "
                AND c.cod_tipo_cdp IN ($tipos)
                AND c.descarga_estado = 'OK'
              ORDER BY (c.mov_msg IS NOT NULL), c.fecha_emision,
                       (c.tipo = 'ventas'), c.id
              LIMIT " . (int) $limite, $p);
    }

    /** Líneas conciliadas de un comprobante (las que apuntan a un producto). */
    private static function lineas(int $cpeId): array
    {
        return DB::todos(
            'SELECT * FROM sunat_cpe_items
              WHERE cpe_id = :c AND producto_id IS NOT NULL AND cantidad > 0
              ORDER BY linea', [':c' => $cpeId]);
    }

    private static function crearEntrada(array $c, array $lineas, int $almacenId): int
    {
        $esDevolucion = ($c['cod_tipo_cdp'] ?? '') === '07';

        return Entrada::registrar([
            'fecha'          => $c['fecha_emision'],
            'almacen_id'     => $almacenId,
            'proveedor_id'   => $c['tipo'] === 'compras' ? self::proveedor($c) : '',
            'tipo_documento' => SunatComprobante::tipoDoc($c['cod_tipo_cdp']),
            'nro_documento'  => $c['serie'] . '-' . $c['numero'],
            'observacion'    => ($esDevolucion ? 'Devolución de cliente' : 'Importado de SUNAT')
                                . ' · ' . mb_substr((string) $c['nombre_contraparte'], 0, 60),
        ], array_map(fn($l) => [
            'producto_id'    => (int) $l['producto_id'],
            'cantidad'       => abs((float) $l['cantidad']),
            'costo_unitario' => abs((float) $l['valor_unitario']),   // sin IGV
        ], $lineas));
    }

    private static function crearSalida(array $c, array $lineas, int $almacenId): int
    {
        $esDevolucion = ($c['cod_tipo_cdp'] ?? '') === '07';

        return Salida::registrar([
            'fecha'       => $c['fecha_emision'],
            'almacen_id'  => $almacenId,
            'motivo'      => $esDevolucion ? 'DEVOLUCION' : 'VENTA',
            'destino'     => mb_substr((string) $c['nombre_contraparte'], 0, 180),
            'observacion' => 'Importado de SUNAT · ' . $c['serie'] . '-' . $c['numero'],
        ], array_map(fn($l) => [
            'producto_id' => (int) $l['producto_id'],
            'cantidad'    => abs((float) $l['cantidad']),
        ], $lineas));
    }

    /** Proveedor del catálogo para ese RUC; se crea si no existe. */
    private static function proveedor(array $c): string
    {
        $ruc = preg_replace('/\D/', '', (string) ($c['ruc_contraparte'] ?? ''));
        if ($ruc === '') {
            return '';
        }
        $id = DB::valor('SELECT id FROM proveedores WHERE ' . Empresa::filtro() . ' AND ruc = :r',
            Empresa::param() + [':r' => $ruc]);
        if ($id) {
            return (string) $id;
        }
        return (string) Catalogo::guardar('proveedores', [
            'ruc'          => $ruc,
            'razon_social' => mb_substr((string) ($c['nombre_contraparte'] ?: $ruc), 0, 180),
            'telefono'     => '', 'email' => '', 'direccion' => '', 'estado' => 1,
        ], null);
    }

    private static function anotar(int $cpeId, ?string $tabla, ?int $movId, ?string $msg): void
    {
        DB::actualizar('sunat_comprobantes', [
            'mov_tabla'   => $tabla,
            'mov_id'      => $movId,
            'mov_msg'     => $msg !== null ? mb_substr($msg, 0, 300) : null,
            'generado_en' => date('Y-m-d H:i:s'),
        ], 'id = :id AND ' . Empresa::filtro(), Empresa::param() + [':id' => $cpeId]);
    }

    /** Comprobantes que no se pudieron convertir, con su motivo. */
    public static function fallidos(int $limite = 100): array
    {
        return DB::todos(
            'SELECT serie, numero, tipo, cod_tipo_cdp, fecha_emision, mov_msg
               FROM sunat_comprobantes
              WHERE ' . Empresa::filtro() . ' AND mov_id IS NULL AND mov_msg IS NOT NULL
              ORDER BY fecha_emision LIMIT ' . (int) $limite, Empresa::param());
    }

    /** Comprobantes ya convertidos, con su movimiento. */
    public static function generados(int $limite = 100): array
    {
        return DB::todos(
            'SELECT c.serie, c.numero, c.tipo, c.cod_tipo_cdp, c.fecha_emision,
                    c.mov_tabla, c.mov_id, c.items_cant,
                    COALESCE(e.serie_numero, s.serie_numero) AS movimiento
               FROM sunat_comprobantes c
               LEFT JOIN entradas e ON c.mov_tabla = \'entradas\' AND e.id = c.mov_id
               LEFT JOIN salidas  s ON c.mov_tabla = \'salidas\'  AND s.id = c.mov_id
              WHERE ' . Empresa::filtro('c') . ' AND c.mov_id IS NOT NULL
              ORDER BY c.fecha_emision DESC, c.id DESC LIMIT ' . (int) $limite, Empresa::param());
    }
}
