<?php
/**
 * Importación masiva de productos desde Excel o CSV. RF-03, RF-14.
 *
 * Trabaja en dos fases:
 *   1. analizar()  — lee el archivo, mapea las columnas por el nombre de la
 *                    cabecera, valida cada fila y devuelve una vista previa
 *                    SIN tocar la base de datos.
 *   2. aplicar()   — vuelve a validar y recién entonces inserta o actualiza,
 *                    todo dentro de una transacción: si una fila falla, no
 *                    queda una carga a medias.
 *
 * El stock nunca se escribe directo: si la plantilla trae stock inicial se
 * genera un movimiento INV_INICIAL en el kardex, para no romper la regla de
 * que toda existencia proviene de un movimiento (RB-02, RB-03).
 */
class ImportadorProductos
{
    /** Columnas reconocidas => sinónimos aceptados en la cabecera. */
    private const COLUMNAS = [
        'codigo'        => ['codigo', 'código', 'cod', 'sku', 'clave'],
        'descripcion'   => ['descripcion', 'descripción', 'producto', 'nombre', 'detalle'],
        'categoria'     => ['categoria', 'categoría', 'linea', 'línea', 'familia'],
        'marca'         => ['marca'],
        'unidad'        => ['unidad', 'und', 'um', 'unidad de medida', 'unidad_medida'],
        'precio_compra' => ['precio compra', 'precio_compra', 'preciocompra', 'costo', 'p. compra', 'costo unitario'],
        'precio_venta'  => ['precio venta', 'precio_venta', 'precioventa', 'precio', 'p. venta'],
        'stock_minimo'  => ['stock minimo', 'stock mínimo', 'stock_minimo', 'minimo', 'mínimo', 'stock min'],
        'stock_inicial' => ['stock inicial', 'stock_inicial', 'stock', 'cantidad', 'existencia'],
        'estado'        => ['estado', 'activo'],
    ];

    private const OBLIGATORIAS = ['codigo', 'descripcion'];
    public  const MAX_FILAS = 5000;

    /**
     * Lee y valida sin escribir nada.
     *
     * @param array $op  crear_faltantes(bool), actualizar_existentes(bool),
     *                   almacen_id(int) para el stock inicial
     */
    public static function analizar(string $ruta, string $nombreOriginal, array $op): array
    {
        $filas = ExcelLector::leer($ruta, $nombreOriginal);
        if (!$filas) {
            throw new RuntimeException('El archivo no contiene datos.');
        }

        // La cabecera no tiene por qué ser la primera fila: muchos archivos
        // traen un título encima. Se busca la fila que sí es cabecera.
        [$filaCabecera, $mapa] = self::ubicarCabecera($filas);

        // Sólo se procesan las filas posteriores a la cabecera.
        $datos = array_filter($filas, fn($n) => $n > $filaCabecera, ARRAY_FILTER_USE_KEY);

        if (count($datos) > self::MAX_FILAS) {
            throw new RuntimeException('El archivo tiene ' . count($datos) . ' filas de datos; el máximo por carga es '
                . self::MAX_FILAS . '. Divídalo en varios archivos.');
        }
        $filas = $datos;

        // Catálogos existentes, en minúsculas, para resolver por nombre.
        $cats  = self::indice('categorias', 'nombre');
        $marcs = self::indice('marcas', 'nombre');
        $unids = self::indice('unidades', 'codigo') + self::indice('unidades', 'nombre');

        $resultado = [
            'filas' => [], 'nuevos' => 0, 'actualizar' => 0, 'errores' => 0, 'omitidos' => 0,
            'catalogos_nuevos' => ['categorias' => [], 'marcas' => [], 'unidades' => []],
            'columnas' => array_keys($mapa),
        ];
        $codigosVistos = [];

        foreach ($filas as $numero => $fila) {
            $r = self::leerFila($fila, $mapa);
            $errores = [];

            $r['codigo'] = mb_strtoupper(trim($r['codigo']));
            if ($r['codigo'] === '')      $errores[] = 'Falta el código.';
            if (trim($r['descripcion']) === '') $errores[] = 'Falta la descripción.';
            if (mb_strlen($r['codigo']) > 40)   $errores[] = 'El código supera los 40 caracteres.';
            if (mb_strlen($r['descripcion']) > 255) $errores[] = 'La descripción supera los 255 caracteres.';

            if ($r['codigo'] !== '' && isset($codigosVistos[$r['codigo']])) {
                $errores[] = 'Código repetido en el archivo (fila ' . $codigosVistos[$r['codigo']] . ').';
            } elseif ($r['codigo'] !== '') {
                $codigosVistos[$r['codigo']] = $numero;
            }

            foreach (['precio_compra', 'precio_venta', 'stock_minimo', 'stock_inicial'] as $campo) {
                $r[$campo] = self::aNumero($r[$campo]);
                if ($r[$campo] === null) {
                    $errores[] = 'El valor de "' . str_replace('_', ' ', $campo) . '" no es un número válido.';
                    $r[$campo] = 0;
                } elseif ($r[$campo] < 0) {
                    $errores[] = 'El valor de "' . str_replace('_', ' ', $campo) . '" no puede ser negativo.';
                }
            }

            // Resolución de catálogos
            $r['categoria_id'] = self::resolver($r['categoria'], $cats, 'categorias', $op, $resultado, $errores);
            $r['marca_id']     = self::resolver($r['marca'],     $marcs, 'marcas',     $op, $resultado, $errores);
            $r['unidad_id']    = self::resolver($r['unidad'],    $unids, 'unidades',   $op, $resultado, $errores);

            if (!$r['unidad_id'] && trim((string) $r['unidad']) === '') {
                // Sin unidad indicada se usa la primera del catálogo.
                $r['unidad_id'] = self::unidadPorDefecto();
                if (!$r['unidad_id']) {
                    $errores[] = 'No hay unidades de medida definidas: cree al menos una.';
                }
            }

            $r['estado'] = self::aEstado($r['estado']);

            // ¿Existe ya?
            $existente = $r['codigo'] !== '' ? Producto::porCodigo($r['codigo']) : null;
            $r['existe']    = (bool) $existente;
            $r['id']        = $existente['id'] ?? null;
            $r['stock_ini'] = (float) ($r['stock_inicial'] ?? 0);

            // Un error descarta la fila, exista o no el producto.
            if ($errores) {
                $r['accion'] = 'error';
                $resultado['errores']++;
            } elseif ($existente && empty($op['actualizar_existentes'])) {
                $r['accion'] = 'omitir';
                $resultado['omitidos']++;
            } elseif ($existente) {
                $r['accion'] = 'actualizar';
                $resultado['actualizar']++;
            } else {
                $r['accion'] = 'crear';
                $resultado['nuevos']++;
            }

            $r['fila']    = $numero;
            $r['errores'] = $errores;
            $resultado['filas'][] = $r;
        }

        foreach ($resultado['catalogos_nuevos'] as $k => $v) {
            $resultado['catalogos_nuevos'][$k] = array_values(array_unique($v));
        }
        return $resultado;
    }

    /** Aplica la importación. Devuelve el conteo de lo realizado. */
    public static function aplicar(string $ruta, string $nombreOriginal, array $op): array
    {
        $previo = self::analizar($ruta, $nombreOriginal, $op);

        $aplicables = array_filter($previo['filas'], fn($f) => in_array($f['accion'], ['crear', 'actualizar'], true));
        if (!$aplicables) {
            throw new RuntimeException('No hay ninguna fila válida para importar.');
        }

        return DB::transaccion(function () use ($aplicables, $op) {
            $creados = 0; $actualizados = 0; $conStock = 0;

            foreach ($aplicables as $f) {
                // Los catálogos que faltaban se crean aquí, ya dentro de la transacción.
                $categoriaId = $f['categoria_id'] ?: self::crearSiFalta('categorias', $f['categoria'], $op);
                $marcaId     = $f['marca_id']     ?: self::crearSiFalta('marcas',     $f['marca'],     $op);
                $unidadId    = $f['unidad_id']    ?: self::crearSiFalta('unidades',   $f['unidad'],    $op);

                if (!$unidadId) {
                    throw new RuntimeException("Fila {$f['fila']}: no se pudo determinar la unidad de medida.");
                }

                $datos = [
                    'codigo'        => $f['codigo'],
                    'descripcion'   => $f['descripcion'],
                    'categoria_id'  => $categoriaId ?: '',
                    'marca_id'      => $marcaId ?: '',
                    'unidad_id'     => $unidadId,
                    'precio_compra' => $f['precio_compra'],
                    'precio_venta'  => $f['precio_venta'],
                    'stock_minimo'  => $f['stock_minimo'],
                    'estado'        => $f['estado'],
                ];

                if ($f['accion'] === 'crear') {
                    $productoId = Producto::guardar($datos, null);
                    $creados++;

                    // El stock inicial entra como movimiento de kardex, nunca directo.
                    if ($f['stock_ini'] > 0 && !empty($op['almacen_id'])) {
                        Kardex::registrar([
                            'producto_id'    => $productoId,
                            'almacen_id'     => (int) $op['almacen_id'],
                            'tipo'           => Kardex::INV_INICIAL,
                            'cantidad'       => $f['stock_ini'],
                            'costo_unitario' => $f['precio_compra'],
                            'origen_tabla'   => 'importacion',
                            'origen_id'      => $productoId,
                            'documento'      => 'CARGA-INICIAL',
                            'motivo'         => 'Carga inicial por importación masiva',
                            'fecha'          => date('Y-m-d H:i:s'),
                        ]);
                        $conStock++;
                    }
                } else {
                    Producto::guardar($datos, (int) $f['id']);
                    $actualizados++;
                }
            }

            $r = ['creados' => $creados, 'actualizados' => $actualizados, 'con_stock' => $conStock];
            Auditoria::registrar('IMPORTAR', 'productos', null, $r);
            return $r;
        });
    }

    // --- Apoyo ---------------------------------------------------------

    /**
     * Busca la fila de cabecera entre las primeras del archivo.
     * @return array{0:int,1:array} número de fila y mapa de columnas
     */
    private static function ubicarCabecera(array $filas): array
    {
        $revisadas = 0;
        foreach ($filas as $numero => $fila) {
            if (++$revisadas > 20) break;      // la cabecera no estará más abajo

            $mapa = self::mapearCabecera($fila);
            $tieneTodas = true;
            foreach (self::OBLIGATORIAS as $obl) {
                if (!isset($mapa[$obl])) { $tieneTodas = false; break; }
            }
            if ($tieneTodas) {
                return [$numero, $mapa];
            }
        }

        throw new RuntimeException(
            'No se encontró la fila de encabezados. Debe existir una fila con al menos las columnas '
            . '"Codigo" y "Descripcion". Descargue la plantilla para ver el formato esperado.');
    }

    /** Cabecera => posición de columna, tolerando acentos y mayúsculas. */
    private static function mapearCabecera(array $cabecera): array
    {
        $mapa = [];
        foreach ($cabecera as $i => $titulo) {
            $limpio = self::normalizar($titulo);
            if ($limpio === '') continue;
            foreach (self::COLUMNAS as $campo => $sinonimos) {
                if (isset($mapa[$campo])) continue;
                foreach ($sinonimos as $s) {
                    if ($limpio === self::normalizar($s)) {
                        $mapa[$campo] = $i;
                        continue 3;
                    }
                }
            }
        }
        return $mapa;
    }

    private static function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        return preg_replace('/\s+/', ' ', $s);
    }

    private static function leerFila(array $fila, array $mapa): array
    {
        $r = [];
        foreach (array_keys(self::COLUMNAS) as $campo) {
            $r[$campo] = isset($mapa[$campo]) ? trim((string) ($fila[$mapa[$campo]] ?? '')) : '';
        }
        return $r;
    }

    /**
     * Acepta "1.234,56" y "1,234.56". Devuelve null si no es numérico.
     *
     * Con un solo separador se interpreta como DECIMAL ("1.000" = 1,0), que es
     * lo predecible y no arruina cantidades como "0.500". Para importar mil,
     * escriba 1000 sin separador de miles. En .xlsx no aplica: las celdas
     * numéricas ya llegan normalizadas por Excel.
     */
    private static function aNumero(string $v): ?float
    {
        $v = trim($v);
        if ($v === '') return 0.0;
        $v = str_replace([' ', "\xc2\xa0"], '', $v);
        $v = preg_replace('/^[A-Za-z\/$€S.]{1,3}\s*/u', '', $v);   // símbolo de moneda

        $ultimaComa  = strrpos($v, ',');
        $ultimoPunto = strrpos($v, '.');
        if ($ultimaComa !== false && $ultimoPunto !== false) {
            // El separador decimal es el que aparece más a la derecha; el otro
            // es de miles y se quita PRIMERO, antes de normalizar el decimal.
            if ($ultimaComa > $ultimoPunto) {
                $v = str_replace(',', '.', str_replace('.', '', $v));   // 1.234,56
            } else {
                $v = str_replace(',', '', $v);                          // 1,234.56
            }
        } elseif ($ultimaComa !== false) {
            $v = str_replace(',', '.', $v);                             // 1234,56
        }

        return is_numeric($v) ? (float) $v : null;
    }

    private static function aEstado(string $v): int
    {
        $v = self::normalizar($v);
        if ($v === '') return 1;
        return in_array($v, ['0', 'no', 'inactivo', 'false', 'n', 'baja'], true) ? 0 : 1;
    }

    /** [nombre en minúsculas => id] de un catálogo de la empresa activa. */
    private static function indice(string $tabla, string $campo): array
    {
        $filas = DB::todos("SELECT id, $campo AS v FROM $tabla WHERE " . Empresa::filtro(), Empresa::param());
        $out = [];
        foreach ($filas as $f) {
            $out[self::normalizar((string) $f['v'])] = (int) $f['id'];
        }
        return $out;
    }

    private static function unidadPorDefecto(): ?int
    {
        $id = DB::valor('SELECT id FROM unidades WHERE ' . Empresa::filtro() . ' ORDER BY id LIMIT 1', Empresa::param());
        return $id ? (int) $id : null;
    }

    /** Resuelve un nombre de catálogo contra lo existente. */
    private static function resolver(string $valor, array $indice, string $tabla,
                                     array $op, array &$resultado, array &$errores): ?int
    {
        $valor = trim($valor);
        if ($valor === '') return null;

        $clave = self::normalizar($valor);
        if (isset($indice[$clave])) {
            return $indice[$clave];
        }
        if (!empty($op['crear_faltantes'])) {
            $resultado['catalogos_nuevos'][$tabla][] = $valor;
            return null;   // se creará al aplicar
        }
        $errores[] = ucfirst($tabla) . ': "' . $valor . '" no existe. '
                   . 'Créelo antes o marque "crear los que falten".';
        return null;
    }

    /** Crea el registro de catálogo durante la aplicación. */
    private static function crearSiFalta(string $tabla, string $valor, array $op): ?int
    {
        $valor = trim($valor);
        if ($valor === '' || empty($op['crear_faltantes'])) {
            return null;
        }
        $campo = $tabla === 'unidades' ? 'codigo' : 'nombre';

        // Puede haberse creado en una fila anterior de esta misma carga.
        $existe = DB::valor(
            "SELECT id FROM $tabla WHERE " . Empresa::filtro() . " AND $campo = :v",
            Empresa::param() + [':v' => $valor]);
        if ($existe) {
            return (int) $existe;
        }

        $datos = $tabla === 'unidades'
            ? ['codigo' => mb_substr(mb_strtoupper($valor), 0, 10), 'nombre' => $valor, 'decimales' => 2]
            : ['nombre' => $valor];

        return Catalogo::guardar($tabla, $datos, null);
    }

    /** Plantilla .xlsx lista para llenar. */
    public static function plantilla(): never
    {
        $x = new Excel('Productos');
        $x->titulo('Plantilla de importación de productos', Empresa::actual()['razon_social']);
        $x->linea('Complete una fila por producto. No cambie los nombres de la primera fila de la tabla.');
        $x->linea('Obligatorias: Código y Descripción. Las demás pueden ir vacías.');
        $x->linea('Stock inicial genera un movimiento de carga inicial en el kardex (sólo en productos nuevos).');

        $x->columnas([
            ['Codigo', 16, 'texto'], ['Descripcion', 42, 'texto'],
            ['Categoria', 20, 'texto'], ['Marca', 18, 'texto'], ['Unidad', 10, 'texto'],
            ['Precio Compra', 15, 'numero'], ['Precio Venta', 15, 'numero'],
            ['Stock Minimo', 14, 'numero'], ['Stock Inicial', 14, 'numero'], ['Estado', 10, 'texto'],
        ]);

        $x->fila(['TOR-001', 'Tornillo hexagonal 1/2 pulgada', 'Ferretería', 'Genérico', 'UND', 2.5, 4.0, 20, 100, 'Activo']);
        $x->fila(['CAB-002', 'Cable eléctrico 2mm x metro',   'Eléctricos', 'Indeco',   'MT',  8.0, 12.0, 15, 250, 'Activo']);
        $x->fila(['PIN-003', 'Pintura látex blanco 4L',       'Pinturas',   'Anypsa',   'UND', 45.0, 70.0, 5, 30, 'Activo']);

        $x->descargar('plantilla_productos.xlsx');
    }
}
