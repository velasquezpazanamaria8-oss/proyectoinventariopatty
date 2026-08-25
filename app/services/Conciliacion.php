<?php
/**
 * Conciliación: emparejar lo que dice SUNAT con el catálogo propio.
 *
 * Es el paso que decide qué producto mueve cada línea de un comprobante.
 * Sin esto, importar crearía un catálogo lleno de duplicados: el mismo
 * artículo llega descrito distinto por cada proveedor.
 *
 * La clave de equivalencia es el CÓDIGO DEL EMISOR junto con SU RUC:
 *  · En ventas el emisor es la propia empresa, así que el código ya es el
 *    del catálogo y el emparejamiento suele ser directo.
 *  · En compras el código es del proveedor, y el 8863 de uno no tiene nada
 *    que ver con el 8863 de otro. Por eso el RUC forma parte de la clave.
 *
 * Si un comprobante no trae código, se cae a la descripción.
 */
class Conciliacion
{

    /**
     * Expresión SQL de la CLAVE de equivalencia de una línea.
     *
     * Se usa el código del emisor sólo si de verdad identifica algo. Muchos
     * proveedores rellenan ese campo con un guion, un punto o "SN", y tomarlo
     * como código fusionaría productos distintos en un mismo grupo: se vio en
     * datos reales un proveedor con SEIS repuestos diferentes, todos con
     * código "-". Emparejar ese grupo habría asignado los seis al mismo
     * producto. Cuando el código no sirve se cae a la descripción.
     *
     * Debe ser IDÉNTICA en todas las consultas que agrupan o buscan por clave:
     * si una difiere, las equivalencias guardadas dejan de encontrarse.
     */
    public static function sqlClave(string $alias = 'i'): string
    {
        $a = $alias === '' ? '' : $alias . '.';
        return "CASE WHEN TRIM(COALESCE({$a}codigo_sunat, '')) REGEXP '[A-Za-z0-9]'
                      AND UPPER(TRIM({$a}codigo_sunat)) NOT IN ('SN', 'NA', 'N/A', 'S/N', '0')
                     THEN TRIM({$a}codigo_sunat)
                     ELSE {$a}descripcion END";
    }

    /** Misma regla, en PHP, para decidir en pantalla si hay código utilizable. */
    public static function codigoUtil(?string $codigo): bool
    {
        $c = trim((string) $codigo);
        return $c !== ''
            && preg_match('/[A-Za-z0-9]/', $c) === 1
            && !in_array(mb_strtoupper($c), ['SN', 'NA', 'N/A', 'S/N', '0'], true);
    }

    /**
     * Ítems distintos por conciliar, con su sugerencia.
     * Agrupa por (RUC del emisor + clave), que es la unidad real de decisión.
     */
    public static function pendientes(array $f = []): array
    {
        $where = [Empresa::filtro('i')];
        $p = Empresa::param();
        if (!empty($f['periodo'])) { $where[] = 'c.periodo = :per'; $p[':per'] = preg_replace('/\D/', '', $f['periodo']); }
        if (!empty($f['tipo']))    { $where[] = 'c.tipo = :t';      $p[':t']   = $f['tipo']; }

        $rucPropio = (string) (CredencialSunat::deEmpresa()['ruc'] ?? '');

        $filas = DB::todos(
            'SELECT
                CASE WHEN c.tipo = \'ventas\' THEN :propio ELSE COALESCE(c.ruc_contraparte, \'\') END AS origen_ruc,
                ' . self::sqlClave('i') . ' AS clave,
                MIN(i.codigo_sunat)   AS codigo_sunat,
                MIN(i.descripcion)    AS descripcion,
                MIN(i.unidad_codigo)  AS unidad_codigo,
                MIN(i.unidad_nombre)  AS unidad_nombre,
                MIN(c.tipo)           AS tipo,
                MIN(c.nombre_contraparte) AS contraparte,
                COUNT(*)              AS lineas,
                MIN(c.fecha_emision)  AS fecha_desde,
                MAX(c.fecha_emision)  AS fecha_hasta,
                -- Meses en los que aparece el producto. Sin esto, al mirar
                -- "Todos" los períodos una fila suma varios meses y no se ve.
                GROUP_CONCAT(DISTINCT c.periodo ORDER BY c.periodo) AS periodos,
                SUM(i.cantidad)       AS cantidad_total,
                MIN(i.valor_unitario) AS precio_min,
                MAX(i.valor_unitario) AS precio_max,
                COUNT(i.producto_id)  AS ya_mapeadas
             FROM sunat_cpe_items i
             JOIN sunat_comprobantes c ON c.id = i.cpe_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY origen_ruc, clave
            ORDER BY MIN(c.tipo), lineas DESC, descripcion',
            $p + [':propio' => $rucPropio]);

        // Estado guardado y sugerencia para cada uno
        foreach ($filas as &$f2) {
            $mapa = self::mapaDe($f2['origen_ruc'], $f2['clave']);
            $f2['mapa']       = $mapa;
            $f2['producto']   = $mapa && $mapa['producto_id'] ? Producto::buscar((int) $mapa['producto_id']) : null;
            $f2['ignorado']   = $mapa ? (bool) $mapa['ignorar'] : false;
            $f2['sugerencia'] = ($mapa === null) ? self::sugerir($f2) : null;
            // Sólo tiene sentido avisar de lo que aún está sin decidir.
            $f2['no_inventario'] = $mapa === null ? self::pareceNoInventario($f2) : null;
        }
        return $filas;
    }

    /**
     * Comprobantes en los que aparece un producto de la lista de conciliación.
     *
     * Sirve para poder mirar la factura antes de decidir: cuando la descripción
     * de SUNAT es ambigua, el PDF es lo único que aclara qué se compró. Se
     * agrupa por comprobante porque un mismo producto puede venir en varias
     * líneas del mismo documento.
     */
    public static function comprobantesDe(string $origenRuc, string $clave, array $f = []): array
    {
        $where = [Empresa::filtro('i')];
        $p = Empresa::param();
        if (!empty($f['periodo'])) { $where[] = 'c.periodo = :per'; $p[':per'] = preg_replace('/\D/', '', $f['periodo']); }
        if (!empty($f['tipo']))    { $where[] = 'c.tipo = :t';      $p[':t']   = $f['tipo']; }

        $where[] = self::sqlClave('i') . ' = :clave';
        $where[] = 'CASE WHEN c.tipo = \'ventas\' THEN :propio
                         ELSE COALESCE(c.ruc_contraparte, \'\') END = :ruc';

        return DB::todos(
            'SELECT c.id, c.tipo, c.cod_tipo_cdp, c.serie, c.numero, c.fecha_emision,
                    c.nombre_contraparte, c.total, c.periodo,
                    c.pdf_path IS NOT NULL AS tiene_pdf,
                    c.xml_path IS NOT NULL AS tiene_xml,
                    c.cdr_path IS NOT NULL AS tiene_cdr,
                    SUM(i.cantidad)       AS cantidad,
                    MIN(i.valor_unitario) AS valor_unitario,
                    MIN(i.descripcion)    AS descripcion,
                    COUNT(*)              AS lineas
               FROM sunat_cpe_items i
               JOIN sunat_comprobantes c ON c.id = i.cpe_id
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY c.id, c.tipo, c.cod_tipo_cdp, c.serie, c.numero, c.fecha_emision,
                       c.nombre_contraparte, c.total, c.periodo, tiene_pdf, tiene_xml, tiene_cdr
              ORDER BY c.fecha_emision DESC, c.id DESC
              LIMIT 200',
            $p + [':clave' => $clave, ':ruc' => $origenRuc,
                  ':propio' => (string) (CredencialSunat::deEmpresa()['ruc'] ?? '')]);
    }

    /** Equivalencia guardada, si existe. */
    public static function mapaDe(string $origenRuc, string $clave): ?array
    {
        return DB::uno(
            'SELECT * FROM sunat_producto_mapa
              WHERE ' . Empresa::filtro() . ' AND origen_ruc = :r AND clave = :c',
            Empresa::param() + [':r' => $origenRuc, ':c' => $clave]);
    }

    /**
     * Propone un producto del catálogo. Primero por código exacto —que es
     * fiable, sobre todo en ventas— y sólo después por descripción, que se
     * ofrece como pista y no como certeza.
     */
    public static function sugerir(array $item): ?array
    {
        $codigo = trim((string) ($item['codigo_sunat'] ?? ''));
        if ($codigo !== '') {
            $p = Producto::porCodigo($codigo);
            if ($p) {
                return ['producto' => Producto::buscar((int) $p['id']), 'motivo' => 'mismo código'];
            }
        }

        $desc = trim((string) ($item['descripcion'] ?? ''));
        if ($desc !== '') {
            $fila = DB::uno(
                'SELECT id FROM productos
                  WHERE ' . Empresa::filtro() . ' AND estado = 1 AND descripcion = :d LIMIT 1',
                Empresa::param() + [':d' => $desc]);
            if ($fila) {
                return ['producto' => Producto::buscar((int) $fila['id']), 'motivo' => 'misma descripción'];
            }
        }
        return null;
    }

    /**
     * Guarda la decisión y la aplica a todas las líneas que correspondan.
     * @param int|null $productoId null + $ignorar = no es inventario (servicio)
     */
    public static function decidir(string $origenRuc, string $clave, ?int $productoId, bool $ignorar, string $descripcion = ''): void
    {
        if ($productoId !== null && !Producto::buscar($productoId)) {
            throw new RuntimeException('El producto no pertenece a la empresa activa.');
        }

        DB::transaccion(function () use ($origenRuc, $clave, $productoId, $ignorar, $descripcion) {
            $existente = self::mapaDe($origenRuc, $clave);

            // "Deshacer" (sin producto y sin ignorar) es volver a NO DECIDIDO.
            // Guardar una fila vacía lo dejaría decidido-en-blanco: pendientes()
            // sólo calcula sugerencia cuando NO hay fila, y aplicarSugerencias()
            // salta todo lo que tenga fila. El ítem quedaría sin sugerencia y
            // fuera del emparejamiento masivo para siempre.
            if ($productoId === null && !$ignorar) {
                if ($existente) {
                    DB::eliminar('sunat_producto_mapa', 'id = :id', [':id' => $existente['id']]);
                }
                self::aplicarA($origenRuc, $clave, null);
                return;
            }

            $datos = [
                'producto_id' => $productoId,
                'ignorar'     => $ignorar ? 1 : 0,
                'descripcion' => mb_substr($descripcion, 0, 500) ?: null,
            ];

            if ($existente) {
                DB::actualizar('sunat_producto_mapa', $datos, 'id = :id', [':id' => $existente['id']]);
            } else {
                DB::insertar('sunat_producto_mapa', Empresa::sello(
                    $datos + ['origen_ruc' => $origenRuc, 'clave' => $clave]));
            }
            self::aplicarA($origenRuc, $clave, $productoId);
        });

        Auditoria::registrar('CONCILIAR', 'sunat_producto_mapa', null,
            ['clave' => $clave, 'ruc' => $origenRuc, 'producto' => $productoId, 'ignorar' => $ignorar]);
    }

    /**
     * Aplica las equivalencias ya aprendidas a las líneas de un comprobante
     * recién descargado.
     *
     * Sin esto, el orden importa: si se concilia y DESPUÉS se descargan más
     * comprobantes, las líneas nuevas quedan sin producto aunque su
     * equivalencia ya estuviera decidida — y la generación de movimientos las
     * saltaría en silencio.
     *
     * @return int líneas emparejadas
     */
    public static function aplicarGuardadosA(int $cpeId): int
    {
        $rucPropio = (string) (CredencialSunat::deEmpresa()['ruc'] ?? '');

        return DB::query(
            'UPDATE sunat_cpe_items i
               JOIN sunat_comprobantes c ON c.id = i.cpe_id
               JOIN sunat_producto_mapa m
                 ON m.empresa_id = i.empresa_id
                AND m.clave = ' . self::sqlClave('i') . '
                AND m.origen_ruc = (CASE WHEN c.tipo = \'ventas\'
                                         THEN :propio ELSE COALESCE(c.ruc_contraparte, \'\') END)
                SET i.producto_id = m.producto_id
              WHERE i.cpe_id = :cpe AND i.empresa_id = :emp AND i.producto_id IS NULL',
            [':propio' => $rucPropio, ':cpe' => $cpeId, ':emp' => Empresa::id()])->rowCount();
    }

    /** Escribe producto_id en las líneas que coinciden con esa clave. */
    private static function aplicarA(string $origenRuc, string $clave, ?int $productoId): int
    {
        $rucPropio = (string) (CredencialSunat::deEmpresa()['ruc'] ?? '');

        return DB::query(
            'UPDATE sunat_cpe_items i
               JOIN sunat_comprobantes c ON c.id = i.cpe_id
                SET i.producto_id = :prod
              WHERE i.empresa_id = :emp
                AND ' . self::sqlClave('i') . ' = :clave
                AND (CASE WHEN c.tipo = \'ventas\' THEN :propio ELSE COALESCE(c.ruc_contraparte, \'\') END) = :ruc',
            [':prod' => $productoId, ':emp' => Empresa::id(), ':clave' => $clave,
             ':propio' => $rucPropio, ':ruc' => $origenRuc])->rowCount();
    }

    /**
     * Crea un producto del catálogo a partir de la línea de SUNAT y lo empareja.
     * El costo se toma del valor unitario SIN IGV, que es el que debe valorizar.
     */
    public static function crearProducto(array $item, string $origenRuc, string $clave): int
    {
        return self::crearProductoCon(self::valoresSugeridos($item, $origenRuc, $clave), $origenRuc, $clave);
    }

    /**
     * Valores con los que se propone crear el producto. Se usan para rellenar
     * el formulario y como respaldo si no llega algún campo.
     */
    public static function valoresSugeridos(array $item, string $origenRuc, string $clave): array
    {
        // Se reutiliza el código del emisor sólo si sirve para identificar:
        // un "-" o un "SN" como código de producto no vale, y además chocaría
        // entre sí en el catálogo. En ese caso se genera uno estable a partir
        // de la clave, para que el mismo ítem proponga siempre el mismo código.
        $codigo = trim((string) ($item['codigo_sunat'] ?? ''));
        if (!self::codigoUtil($codigo)) {
            $codigo = 'SUN-' . strtoupper(substr(md5($origenRuc . '|' . $clave), 0, 8));
        }

        // El precio del ítem significa cosas distintas según de dónde venga:
        // en una compra es lo que costó, en una venta es a cuánto se vendió.
        // Ponerlo siempre como precio de compra dejaría el costo inflado.
        $precio  = round((float) ($item['precio_min'] ?? 0), 4);
        $esVenta = ($item['tipo'] ?? '') === 'ventas';

        return [
            'codigo'        => self::codigoLibre($codigo),
            'descripcion'   => mb_substr(trim((string) ($item['descripcion'] ?? '')), 0, 255),
            'categoria_id'  => '',
            'marca_id'      => '',
            'unidad_id'     => self::unidad((string) ($item['unidad_codigo'] ?? ''),
                                            (string) ($item['unidad_nombre'] ?? '')),
            'precio_compra' => $esVenta ? 0 : $precio,
            'precio_venta'  => $esVenta ? $precio : 0,
            'stock_minimo'  => 0,
            'estado'        => 1,
        ];
    }

    /**
     * Crea el producto con los datos que el usuario confirmó en el formulario
     * y lo deja emparejado con la clave de SUNAT.
     */
    public static function crearProductoCon(array $datos, string $origenRuc, string $clave): int
    {
        $codigo = trim((string) ($datos['codigo'] ?? ''));
        if ($codigo === '') {
            throw new InvalidArgumentException('El código del producto no puede quedar vacío.');
        }
        if (trim((string) ($datos['descripcion'] ?? '')) === '') {
            throw new InvalidArgumentException('La descripción no puede quedar vacía.');
        }
        // El usuario pudo tardar en enviar el formulario y el código quedar
        // ocupado entretanto: se resuelve en vez de fallar con un duplicado.
        $datos['codigo'] = self::codigoLibre($codigo);

        $productoId = Producto::guardar([
            'codigo'        => $datos['codigo'],
            'descripcion'   => mb_substr(trim((string) $datos['descripcion']), 0, 255),
            'categoria_id'  => $datos['categoria_id'] ?? '',
            'marca_id'      => $datos['marca_id'] ?? '',
            'unidad_id'     => (int) $datos['unidad_id'],
            'precio_compra' => round((float) ($datos['precio_compra'] ?? 0), 4),
            'precio_venta'  => round((float) ($datos['precio_venta'] ?? 0), 4),
            'stock_minimo'  => round((float) ($datos['stock_minimo'] ?? 0), 4),
            'estado'        => 1,
        ]);

        self::decidir($origenRuc, $clave, $productoId, false, (string) ($datos['descripcion'] ?? ''));
        return $productoId;
    }

    /** Devuelve el código libre más cercano al propuesto. */
    private static function codigoLibre(string $codigo): string
    {
        $base = mb_substr($codigo, 0, 36);
        $libre = $base;
        $n = 1;
        while (Producto::porCodigo($libre)) {
            $libre = $base . '-' . (++$n);
        }
        return $libre;
    }

    /**
     * Unidad del catálogo equivalente al código de SUNAT (NIU, BJ, KGM...).
     * Si no existe se crea: son códigos estándar y crearlos a mano para cada
     * empresa sería trabajo inútil.
     */
    public static function unidad(string $codigoSunat, string $nombre = ''): int
    {
        $codigo = strtoupper(trim($codigoSunat)) ?: 'NIU';

        // Equivalencias con las unidades que ya suele tener el catálogo.
        $equivale = ['NIU' => 'UND', 'ZZ' => 'UND', 'KGM' => 'KG', 'LTR' => 'LT', 'MTR' => 'MT'];
        $buscar = $equivale[$codigo] ?? $codigo;

        $id = DB::valor('SELECT id FROM unidades WHERE ' . Empresa::filtro() . ' AND codigo = :c',
            Empresa::param() + [':c' => $buscar]);
        if ($id) {
            return (int) $id;
        }

        return Catalogo::guardar('unidades', [
            'codigo'    => mb_substr($buscar, 0, 10),
            'nombre'    => mb_substr($nombre ?: $buscar, 0, 60),
            'decimales' => 2,
        ], null);
    }

    /** Aplica de una vez todas las sugerencias por código exacto. */
    public static function aplicarSugerencias(array $f = []): int
    {
        $n = 0;
        foreach (self::pendientes($f) as $item) {
            if ($item['mapa'] !== null || $item['sugerencia'] === null) {
                continue;
            }
            if ($item['sugerencia']['motivo'] !== 'mismo código') {
                continue;   // por descripción NO se decide solo: es una pista
            }
            self::decidir($item['origen_ruc'], $item['clave'],
                (int) $item['sugerencia']['producto']['id'], false, $item['descripcion']);
            $n++;
        }
        return $n;
    }

    /** Resumen del avance de la conciliación. */
    public static function avance(array $f = []): array
    {
        $items = self::pendientes($f);
        $r = ['total' => count($items), 'mapeados' => 0, 'ignorados' => 0,
              'sin_decidir' => 0, 'sugeridos' => 0, 'no_inventario' => 0];

        foreach ($items as $i) {
            if ($i['ignorado'])            $r['ignorados']++;
            elseif ($i['producto'])        $r['mapeados']++;
            else {
                $r['sin_decidir']++;
                if ($i['sugerencia']) $r['sugeridos']++;
                if ($i['no_inventario']) $r['no_inventario']++;
            }
        }
        return $r;
    }


    /**
     * Conceptos que casi nunca son mercadería.
     *
     * Un comprobante no sólo trae productos: trae anticipos, comisiones,
     * detracciones, intereses, fletes y servicios sueltos. Meter eso al kardex
     * lo ensucia —«ANTICIPO: FACTURA NRO. E001-771» no es un artículo que
     * entre y salga del almacén— y además descuadra el inventario, porque
     * suelen venir con importes negativos o con cantidad 1 y valor enorme.
     *
     * Esto NO decide nada solo: marca la fila para que salte a la vista y se
     * pueda ignorar en bloque. La palabra final es siempre de quien concilia,
     * porque una ferretería sí puede vender una «manguera de agua» y un taller
     * sí puede facturar un «servicio» que lleve repuestos dentro.
     *
     * Se buscan palabras enteras: «PRIMA» no debe saltar dentro de «PRIMARIA».
     */
    private const CONCEPTOS = [
        'anticipo'    => 'ANTICIPOS?|ADELANTOS?',
        'detracción'  => 'DETRACC(?:ION|IÓN)(?:ES)?',
        'comisión'    => 'COMISI(?:ON|ÓN)(?:ES)?',
        'interés'     => 'INTER(?:ES|ÉS)(?:ES)?|MORA|PENALIDAD(?:ES)?',
        'retención'   => 'RETENCI(?:ON|ÓN)(?:ES)?|PERCEPCI(?:ON|ÓN)(?:ES)?',
        'flete'       => 'FLETES?|ESTIBAS?|DESESTIBAS?',
        // «SERVICIOS GENERALES» va en la razón social de media Perú y aparece
        // dentro de la descripción de productos normales; no cuenta.
        'servicio'    => 'SERVICIOS?(?!\s+GENERALES)|HONORARIOS?|ASESOR(?:IA|ÍA)|CONSULTOR(?:IA|ÍA)',
        'alquiler'    => 'ALQUILER(?:ES)?|ARRENDAMIENTOS?|MERCED\s+CONDUCTIVA',
        'prima'       => 'PRIMA\s+COMERCIAL|PRIMAS?\s+DE\s+SEGURO',
        'financiero'  => 'ITF|PORTES?|GASTOS?\s+(?:BANCARIOS?|ADMINISTRATIVOS?|NOTARIALES?)',
        'ajuste'      => 'REDONDEOS?|DESCUENTOS?\s+GLOBAL(?:ES)?|AJUSTES?\s+DE\s+PRECIO',
    ];

    /**
     * ¿Esta fila huele a que no es mercadería? Devuelve el motivo, o null.
     *
     * Se mira la descripción y también el importe: un valor unitario negativo
     * es, casi sin excepción, un ajuste o un anticipo que se descuenta, nunca
     * un artículo.
     */
    public static function pareceNoInventario(array $item): ?array
    {
        $desc = mb_strtoupper(trim((string) ($item['descripcion'] ?? '')));

        foreach (self::CONCEPTOS as $motivo => $patron) {
            if (preg_match('/(?:^|[^A-ZÁÉÍÓÚÑ])(?:' . $patron . ')(?:[^A-ZÁÉÍÓÚÑ]|$)/u', $desc)) {
                return ['motivo' => $motivo, 'detalle' => 'la descripción dice «' . $motivo . '»'];
            }
        }

        // Referencia a otro comprobante dentro de la descripción: es un
        // documento que se regulariza, no algo que se guarde en el almacén.
        if (preg_match('/\b[A-Z]{1,4}\d{2,4}\s*-\s*\d+\b/u', $desc)
            && preg_match('/\b(?:FACTURA|BOLETA|NOTA|N(?:RO|°|º)\.?)\b/u', $desc)) {
            return ['motivo' => 'referencia a otro comprobante',
                    'detalle' => 'la descripción apunta a otro comprobante'];
        }

        if ((float) ($item['precio_min'] ?? 0) < 0 || (float) ($item['precio_max'] ?? 0) < 0) {
            return ['motivo' => 'importe negativo',
                    'detalle' => 'el valor unitario es negativo'];
        }

        return null;
    }

    /**
     * Marca como «no es inventario» todo lo que la detección señala.
     *
     * Va en bloque porque son siempre los mismos conceptos mes tras mes, y
     * decidirlos de uno en uno es lo que hace que la conciliación se abandone
     * a medias. Sólo toca lo que está sin decidir: nada de lo ya emparejado
     * se pierde.
     *
     * @return int filas marcadas
     */
    public static function ignorarConceptos(array $f = []): int
    {
        $n = 0;
        foreach (self::pendientes($f) as $i) {
            if ($i['mapa'] !== null || !$i['no_inventario']) {
                continue;
            }
            self::decidir($i['origen_ruc'], $i['clave'], null, true, (string) $i['descripcion']);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Stock inicial
    // ------------------------------------------------------------------

    /** Productos ya conciliados, con su stock inicial capturado. */
    public static function stockInicial(int $almacenId): array
    {
        $filas = DB::todos(
            'SELECT pr.id, pr.codigo, pr.descripcion, un.codigo AS unidad,
                    COALESCE(si.cantidad, 0)       AS cantidad,
                    COALESCE(si.costo_unitario, 0) AS costo_unitario,
                    si.aplicado_en,
                    -- Costo con el que proponer la carga inicial: lo que de
                    -- verdad costó ese producto según las compras ya
                    -- importadas. Sin esta ayuda el campo nace en cero y el
                    -- saldo inicial entra al kardex sin valor, dejando el
                    -- inventario valorizado por debajo de lo que vale.
                    COALESCE((SELECT ROUND(AVG(i2.valor_unitario), 4)
                                FROM sunat_cpe_items i2
                                JOIN sunat_comprobantes c2 ON c2.id = i2.cpe_id
                               WHERE i2.producto_id = pr.id AND c2.tipo = \'compras\'
                                 AND i2.valor_unitario > 0),
                             NULLIF(pr.precio_compra, 0), 0) AS costo_sugerido
               FROM sunat_producto_mapa m
               JOIN productos pr ON pr.id = m.producto_id
               JOIN unidades  un ON un.id = pr.unidad_id
               LEFT JOIN sunat_stock_inicial si
                      ON si.producto_id = pr.id AND si.almacen_id = :alm AND si.empresa_id = pr.empresa_id
              WHERE ' . Empresa::filtro('m') . ' AND m.producto_id IS NOT NULL
              GROUP BY pr.id, pr.codigo, pr.descripcion, un.codigo, si.cantidad, si.costo_unitario, si.aplicado_en
              ORDER BY pr.descripcion',
            Empresa::param() + [':alm' => $almacenId]);

        // La cantidad mínima se calcula recorriendo el histórico, cosa que en
        // SQL saldría enrevesada: se hace de una vez para todos los productos.
        $minimos = self::cantidadesMinimas();
        foreach ($filas as &$f) {
            $f['cantidad_minima'] = $minimos[(int) $f['id']] ?? 0.0;
        }
        return $filas;
    }

    /**
     * Saldo inicial MÍNIMO que necesita cada producto.
     *
     * Se recorren sus comprobantes por fecha sumando compras y restando ventas:
     * lo más negativo que llegue a estar es lo que faltaba al empezar. Sin este
     * número el usuario tiene que adivinar la cantidad, y si se queda corto las
     * ventas fallan por «stock insuficiente», se convierten más tarde y el
     * kardex acaba con saldos negativos en las fechas intermedias.
     *
     * Dentro del mismo día se cuentan primero las compras, igual que al generar
     * los movimientos: SUNAT no da la hora y la mercadería tiene que existir
     * antes de venderse.
     *
     * @return array<int,float> producto_id => cantidad mínima
     */
    public static function cantidadesMinimas(): array
    {
        $lineas = DB::todos(
            'SELECT i.producto_id, c.tipo, i.cantidad
               FROM sunat_cpe_items i
               JOIN sunat_comprobantes c ON c.id = i.cpe_id
              WHERE ' . Empresa::filtro('i') . ' AND i.producto_id IS NOT NULL
              ORDER BY i.producto_id, c.fecha_emision, (c.tipo = \'ventas\'), c.id, i.linea',
            Empresa::param());

        $saldo = [];
        $peor  = [];
        foreach ($lineas as $l) {
            $pid = (int) $l['producto_id'];
            $saldo[$pid] = ($saldo[$pid] ?? 0.0)
                + ($l['tipo'] === 'compras' ? (float) $l['cantidad'] : -(float) $l['cantidad']);
            if (!isset($peor[$pid]) || $saldo[$pid] < $peor[$pid]) {
                $peor[$pid] = $saldo[$pid];
            }
        }

        $minimos = [];
        foreach ($peor as $pid => $p) {
            $minimos[$pid] = $p < 0 ? round(-$p, 4) : 0.0;
        }
        return $minimos;
    }

    /** Productos con saldo inicial pero sin costo: entrarían al kardex sin valor. */
    public static function sinCostoInicial(int $almacenId): int
    {
        return (int) DB::valor(
            'SELECT COUNT(*) FROM sunat_stock_inicial
              WHERE ' . Empresa::filtro() . ' AND almacen_id = :a
                AND cantidad > 0 AND costo_unitario = 0',
            Empresa::param() + [':a' => $almacenId]);
    }

    /** Guarda las cantidades iniciales. Todavía NO mueve stock: eso es la fase 4. */
    public static function guardarStockInicial(int $almacenId, array $cantidades, array $costos): int
    {
        // El almacén llega del formulario: sin comprobarlo se podría guardar
        // (y luego mover) stock en un almacén de otra empresa — la FK de
        // sunat_stock_inicial apunta a almacenes(id) sin filtrar por empresa.
        if (!Catalogo::buscar('almacenes', $almacenId)) {
            throw new RuntimeException('El almacén no pertenece a la empresa activa.');
        }

        $n = 0;
        DB::transaccion(function () use ($almacenId, $cantidades, $costos, &$n) {
            foreach ($cantidades as $productoId => $cantidad) {
                $productoId = (int) $productoId;
                if (!Producto::buscar($productoId)) {
                    continue;                    // no es de esta empresa
                }
                $cant  = round((float) $cantidad, 4);
                $costo = round((float) ($costos[$productoId] ?? 0), 4);
                if ($cant < 0) {
                    throw new InvalidArgumentException('El stock inicial no puede ser negativo.');
                }

                $existe = DB::valor(
                    'SELECT id FROM sunat_stock_inicial
                      WHERE ' . Empresa::filtro() . ' AND producto_id = :p AND almacen_id = :a',
                    Empresa::param() + [':p' => $productoId, ':a' => $almacenId]);

                if ($existe) {
                    DB::actualizar('sunat_stock_inicial',
                        ['cantidad' => $cant, 'costo_unitario' => $costo],
                        'id = :id', [':id' => $existe]);
                } else {
                    DB::insertar('sunat_stock_inicial', Empresa::sello([
                        'producto_id' => $productoId, 'almacen_id' => $almacenId,
                        'cantidad' => $cant, 'costo_unitario' => $costo,
                    ]));
                }
                $n++;
            }
        });
        Auditoria::registrar('STOCK_INICIAL', 'sunat_stock_inicial', null, ['productos' => $n]);
        return $n;
    }
}
