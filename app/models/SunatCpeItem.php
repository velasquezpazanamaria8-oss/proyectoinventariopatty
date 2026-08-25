<?php
/**
 * Líneas de producto de cada comprobante descargado.
 *
 * Es la pieza que faltaba para el inventario: el SIRE sólo da cabeceras, y
 * aquí queda el detalle (qué producto, cuánto y a qué costo). El campo
 * `producto_id` empieza vacío y lo llena la conciliación de la Fase 3.
 */
class SunatCpeItem
{
    /** Estado de descarga de un comprobante. */
    public const PENDIENTE = 'PENDIENTE';
    public const OK        = 'OK';
    public const ERROR     = 'ERROR';

    /**
     * Guarda las líneas y el estado de descarga de un comprobante.
     * Volver a descargar el mismo comprobante reemplaza sus líneas: así una
     * corrección en SUNAT se refleja sin duplicar.
     */
    public static function guardar(int $cpeId, array $items, array $archivos, array $faltan): void
    {
        DB::transaccion(function () use ($cpeId, $items, $archivos, $faltan) {
            DB::eliminar('sunat_cpe_items', 'cpe_id = :c', [':c' => $cpeId]);

            foreach ($items as $it) {
                DB::insertar('sunat_cpe_items', [
                    'empresa_id'     => Empresa::id(),
                    'cpe_id'         => $cpeId,
                    'linea'          => $it['linea'],
                    'codigo_sunat'   => $it['codigo_sunat'] ?: null,
                    'descripcion'    => $it['descripcion'],
                    'cantidad'       => $it['cantidad'],
                    'unidad_codigo'  => $it['unidad_codigo'] ?: null,
                    'unidad_nombre'  => $it['unidad_nombre'] ?: null,
                    'valor_unitario' => $it['valor_unitario'],
                    'importe'        => $it['importe'],
                ]);
            }

            // Las equivalencias ya decididas se aplican de una vez a lo que
            // acaba de llegar, para que no dependa del orden entre conciliar
            // y descargar.
            Conciliacion::aplicarGuardadosA($cpeId);

            // Un comprobante está completo cuando tiene XML y PDF. El CDR se
            // excluye a propósito: SUNAT responde 422 para todos por esta vía,
            // así que exigirlo dejaría todo en reintento permanente.
            //
            // Si falta XML o PDF por un fallo transitorio, queda PENDIENTE
            // para que se reintente — pero con un tope de intentos, o un
            // archivo que SUNAT nunca va a dar reintentaría para siempre.
            // Los ítems ya extraídos se conservan igual: vienen de la
            // metadata, no del XML, así que no se pierden.
            $completo = isset($archivos['xml']) && isset($archivos['pdf']);
            $intentos = (int) DB::valor('SELECT descarga_intentos FROM sunat_comprobantes WHERE id = :id',
                [':id' => $cpeId]) + 1;

            DB::actualizar('sunat_comprobantes', [
                'descarga_estado'   => ($completo || $intentos >= 3) ? self::OK : self::PENDIENTE,
                'descarga_msg'      => $faltan ? 'Sin: ' . implode(', ', array_keys($faltan)) : null,
                'descarga_intentos' => $intentos,
                'items_cant'        => count($items),
                'xml_path'          => $archivos['xml'] ?? null,
                'pdf_path'          => $archivos['pdf'] ?? null,
                'cdr_path'          => $archivos['cdr'] ?? null,
                'descargado_en'     => date('Y-m-d H:i:s'),
            ], 'id = :id AND ' . Empresa::filtro(), Empresa::param() + [':id' => $cpeId]);
        });
    }

    /**
     * Registra las líneas de un XML que el usuario consiguió por su cuenta.
     *
     * Se separa de guardar() porque las reglas son distintas: aquí no hay
     * intentos que contar —no se llamó a SUNAT— y el comprobante queda por
     * terminado aunque falte el PDF, que es sólo la representación impresa. Lo
     * que el inventario necesita son las líneas, y ya están.
     *
     * El PDF que hubiera se conserva: un XML subido a mano no debe borrar un
     * archivo que sí se llegó a bajar.
     */
    public static function guardarManual(int $cpeId, array $items, array $rutas): void
    {
        DB::transaccion(function () use ($cpeId, $items, $rutas) {
            DB::eliminar('sunat_cpe_items', 'cpe_id = :c', [':c' => $cpeId]);

            foreach ($items as $it) {
                DB::insertar('sunat_cpe_items', Empresa::sello([
                    'cpe_id'         => $cpeId,
                    'linea'          => $it['linea'],
                    'codigo_sunat'   => $it['codigo_sunat'] ?: null,
                    'descripcion'    => $it['descripcion'],
                    'cantidad'       => $it['cantidad'],
                    'unidad_codigo'  => $it['unidad_codigo'] ?: null,
                    'unidad_nombre'  => $it['unidad_nombre'] ?: null,
                    'valor_unitario' => $it['valor_unitario'],
                    'importe'        => $it['importe'],
                ]));
            }

            Conciliacion::aplicarGuardadosA($cpeId);

            $datos = [
                'descarga_estado' => self::OK,
                'descarga_msg'    => 'XML cargado a mano',
                'items_cant'      => count($items),
                'xml_path'        => $rutas['xml'],
                'descargado_en'   => date('Y-m-d H:i:s'),
            ];
            if (!empty($rutas['cdr'])) {
                $datos['cdr_path'] = $rutas['cdr'];
            }

            DB::actualizar('sunat_comprobantes', $datos,
                'id = :id AND ' . Empresa::filtro(), Empresa::param() + [':id' => $cpeId]);
        });

        Auditoria::registrar('CPE_XML_MANUAL', 'sunat_comprobantes', $cpeId,
            ['lineas' => count($items)]);
    }

    /**
     * Adjunta el XML a un comprobante que YA tiene sus líneas.
     *
     * Aquí no se tocan las líneas a propósito: ya vinieron de la metadata de
     * SUNAT y el comprobante puede estar conciliado o incluso convertido en
     * movimientos. Lo que faltaba era el archivo firmado, que es lo que se
     * enseña en una revisión.
     */
    public static function adjuntarXml(int $cpeId, array $rutas, string $nota): void
    {
        $datos = [
            'xml_path'     => $rutas['xml'],
            'descarga_msg' => $nota,
        ];
        if (!empty($rutas['cdr'])) {
            $datos['cdr_path'] = $rutas['cdr'];
        }

        DB::actualizar('sunat_comprobantes', $datos,
            'id = :id AND ' . Empresa::filtro(), Empresa::param() + [':id' => $cpeId]);

        Auditoria::registrar('CPE_XML_ADJUNTADO', 'sunat_comprobantes', $cpeId);
    }

    /**
     * Anota un fallo de descarga sin perder el motivo. Suma un intento: sin
     * eso, un comprobante que SUNAT nunca va a entregar se reintentaría en
     * cada lote y bloquearía el avance del período.
     */
    public static function anotarError(int $cpeId, string $mensaje): void
    {
        DB::query(
            'UPDATE sunat_comprobantes
                SET descarga_estado = :e, descarga_msg = :m,
                    descarga_intentos = descarga_intentos + 1, descargado_en = NOW()
              WHERE id = :id AND ' . Empresa::filtro(),
            Empresa::param() + [':e' => self::ERROR, ':m' => mb_substr($mensaje, 0, 250), ':id' => $cpeId]);
    }

    /** Días tras los cuales se vuelve a intentar un comprobante agotado. */
    public const DIAS_CADUCIDAD = 30;

    /**
     * Comprobantes del período por descargar. Incluye los que fallaron y los
     * que quedaron incompletos, siempre que no hayan agotado los intentos.
     *
     * Los intentos agotados CADUCAN a los 30 días: SUNAT publica comprobantes
     * con retraso —el emisor puede transmitir tarde— así que dar por perdido
     * algo para siempre cementa como inexistente lo que sí acabó existiendo.
     */
    public static function pendientes(string $periodo, int $limite = 10): array
    {
        return DB::todos(
            'SELECT * FROM sunat_comprobantes
              WHERE ' . Empresa::filtro() . ' AND periodo = :per
                AND (descarga_estado IS NULL OR descarga_estado <> :ok)
                AND (descarga_intentos < 3
                     OR descargado_en < DATE_SUB(NOW(), INTERVAL ' . self::DIAS_CADUCIDAD . ' DAY))
              ORDER BY fecha_emision, id
              LIMIT ' . (int) $limite,
            Empresa::param() + [':per' => $periodo, ':ok' => self::OK]);
    }

    /**
     * Reinicia los intentos de un período para forzar el reintento ya.
     *
     * El motivo del último fallo NO se borra: es lo único que explica por qué
     * ese comprobante está ahí, y sin él la pantalla mostraría una lista de
     * errores sin decir de qué. Se sustituye en cuanto se vuelva a intentar.
     */
    public static function reintentar(string $periodo): int
    {
        $n = DB::query(
            'UPDATE sunat_comprobantes
                SET descarga_intentos = 0
              WHERE ' . Empresa::filtro() . ' AND periodo = :per AND descarga_estado <> :ok',
            Empresa::param() + [':per' => $periodo, ':ok' => self::OK])->rowCount();

        Auditoria::registrar('REINTENTAR_CPE', 'sunat_comprobantes', $periodo, ['comprobantes' => $n]);
        return $n;
    }

    /**
     * Avance de la descarga de un período.
     *
     * `por_intentar` cuenta lo que pendientes() devolvería de verdad. No es lo
     * mismo que `pendientes`: un comprobante que falló por un 500 transitorio
     * queda en ERROR —y por tanto fuera de `pendientes`— pero se reintenta.
     * Usar `pendientes` para decidir si queda trabajo deja la pantalla dando
     * por terminado un período lleno de errores recuperables.
     */
    public static function avance(string $periodo): array
    {
        $r = DB::uno(
            'SELECT COUNT(*) AS total,
                    SUM(descarga_estado = :ok)  AS ok,
                    SUM(descarga_estado = :err) AS error,
                    SUM((descarga_estado IS NULL OR descarga_estado <> :ok2)
                        AND (descarga_intentos < 3
                             OR descargado_en < DATE_SUB(NOW(), INTERVAL ' . self::DIAS_CADUCIDAD . ' DAY)))
                                                AS por_intentar,
                    COALESCE(SUM(items_cant),0) AS items
               FROM sunat_comprobantes
              WHERE ' . Empresa::filtro() . ' AND periodo = :per',
            Empresa::param() + [':per' => $periodo, ':ok' => self::OK, ':ok2' => self::OK, ':err' => self::ERROR]);

        $total = (int) $r['total'];
        $ok    = (int) $r['ok'];
        return [
            'total'        => $total,
            'ok'           => $ok,
            'error'        => (int) $r['error'],
            'pendientes'   => $total - $ok - (int) $r['error'],
            'por_intentar' => (int) $r['por_intentar'],
            'items'        => (int) $r['items'],
            'porcentaje'   => $total ? (int) round(($ok + (int) $r['error']) * 100 / $total) : 0,
        ];
    }

    /** Ítems de un período, con su comprobante. Base de la conciliación. */
    public static function listar(array $f = [], int $limite = 1000): array
    {
        $where = [Empresa::filtro('i')];
        $p = Empresa::param();

        if (!empty($f['periodo'])) { $where[] = 'c.periodo = :per'; $p[':per'] = preg_replace('/\D/', '', $f['periodo']); }
        if (!empty($f['tipo']))    { $where[] = 'c.tipo = :t';      $p[':t']   = $f['tipo']; }
        if (!empty($f['q'])) {
            $where[] = '(i.descripcion LIKE :q1 OR i.codigo_sunat LIKE :q2)';
            $t = '%' . $f['q'] . '%';
            $p[':q1'] = $t; $p[':q2'] = $t;
        }
        if (($f['sin_producto'] ?? '') === '1') { $where[] = 'i.producto_id IS NULL'; }

        return DB::todos(
            'SELECT i.*, c.tipo, c.periodo, c.serie, c.numero, c.fecha_emision,
                    c.nombre_contraparte, c.ruc_contraparte, c.cod_tipo_cdp,
                    pr.codigo AS producto_codigo, pr.descripcion AS producto_desc
               FROM sunat_cpe_items i
               JOIN sunat_comprobantes c ON c.id = i.cpe_id
               LEFT JOIN productos pr ON pr.id = i.producto_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY c.fecha_emision, c.serie, i.linea
              LIMIT ' . (int) $limite, $p);
    }

    /**
     * Ítems distintos, agrupados por lo que los identifica: el código del
     * emisor cuando existe, y si no, la descripción. Es la vista que necesita
     * la conciliación: no interesan 500 líneas sino los productos distintos.
     */
    public static function distintos(array $f = []): array
    {
        $where = [Empresa::filtro('i')];
        $p = Empresa::param();
        if (!empty($f['periodo'])) { $where[] = 'c.periodo = :per'; $p[':per'] = preg_replace('/\D/', '', $f['periodo']); }
        if (!empty($f['tipo']))    { $where[] = 'c.tipo = :t';      $p[':t']   = $f['tipo']; }

        return DB::todos(
            'SELECT ' . Conciliacion::sqlClave('i') . ' AS clave,
                    MIN(i.codigo_sunat)  AS codigo_sunat,
                    MIN(i.descripcion)   AS descripcion,
                    MIN(i.unidad_codigo) AS unidad_codigo,
                    COUNT(*)             AS veces,
                    SUM(i.cantidad)      AS cantidad_total,
                    MIN(i.valor_unitario) AS precio_min,
                    MAX(i.valor_unitario) AS precio_max,
                    MIN(c.tipo)          AS tipo,
                    COUNT(DISTINCT c.ruc_contraparte) AS contrapartes,
                    MAX(i.producto_id)   AS producto_id
               FROM sunat_cpe_items i
               JOIN sunat_comprobantes c ON c.id = i.cpe_id
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY clave
              ORDER BY veces DESC, descripcion', $p);
    }
}
