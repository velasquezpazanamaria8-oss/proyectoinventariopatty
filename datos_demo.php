<?php
/**
 * Carga un juego de datos de demostración para probar TODAS las funciones.
 *
 *   Navegador: http://localhost/proyectoinventariopatty/datos_demo.php
 *   Consola:   php datos_demo.php
 *
 * Con --forzar (o ?forzar=1) reinstala la base desde cero antes de cargar.
 * Es un archivo de pruebas: ELIMINARLO antes de poner el sistema en producción.
 *
 * Qué deja preparado:
 *   · 2 empresas, para comprobar el aislamiento multiempresa
 *   · usuarios de los 5 roles, uno de ellos en ambas empresas con rol distinto
 *   · 2 almacenes, catálogos completos y 18 productos
 *   · productos con stock normal, en stock mínimo, agotados y uno inactivo
 *   · entradas, salidas, ajustes y traslados repartidos en los últimos 60 días
 *   · un inventario físico CERRADO (ya conciliado) y otro ABIERTO a medio contar
 */

require_once __DIR__ . '/bootstrap.php';

$esCli   = PHP_SAPI === 'cli';
$forzar  = in_array('--forzar', $argv ?? [], true) || isset($_GET['forzar']);
if (!$esCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

function paso(string $t = ''): void { echo $t . PHP_EOL; }

/**
 * Cambia el usuario y la empresa activos sin pasar por el login, para poder
 * atribuir los movimientos a distintas personas dentro del mismo script.
 */
function actuarComo(string $usuario, int $empresaId): void
{
    $u = DB::uno('SELECT * FROM usuarios WHERE usuario = :u', [':u' => $usuario]);
    if (!$u) {
        throw new RuntimeException("No existe el usuario $usuario");
    }
    Sesion::set('usuario', [
        'id' => (int) $u['id'], 'usuario' => $u['usuario'], 'nombres' => $u['nombres'],
    ]);
    if (!Empresa::activar($empresaId, (int) $u['id'])) {
        throw new RuntimeException("$usuario no tiene acceso a la empresa $empresaId");
    }
}

/** Fecha relativa a hoy, en formato de base de datos. */
function hace(int $dias): string { return date('Y-m-d', strtotime("-$dias days")); }

/** Id de un catálogo por su nombre, dentro de la empresa activa. */
function cat(string $tabla, string $nombre): int
{
    $campo = $tabla === 'unidades' ? 'codigo' : ($tabla === 'proveedores' ? 'razon_social' : 'nombre');
    $id = DB::valor("SELECT id FROM $tabla WHERE $campo = :n AND " . Empresa::filtro(),
        Empresa::param() + [':n' => $nombre]);
    if (!$id) {
        throw new RuntimeException("No se encontró $nombre en $tabla");
    }
    return (int) $id;
}

function prod(string $codigo): int
{
    $p = Producto::porCodigo($codigo);
    if (!$p) throw new RuntimeException("No existe el producto $codigo");
    return (int) $p['id'];
}

/**
 * Crea un registro de catálogo sólo si no existe ya en la empresa activa
 * (la semilla del instalador ya trae algunos). Devuelve su id.
 */
function asegurar(string $tabla, array $datos): int
{
    $campo = $tabla === 'unidades' ? 'codigo' : ($tabla === 'proveedores' ? 'razon_social' : 'nombre');
    $id = DB::valor("SELECT id FROM $tabla WHERE $campo = :n AND " . Empresa::filtro(),
        Empresa::param() + [':n' => $datos[$campo]]);
    return $id ? (int) $id : Catalogo::guardar($tabla, $datos, null);
}

// ---------------------------------------------------------------------
try {
    // Reinstalación opcional
    if ($forzar) {
        $cfg = Config::get('db');
        $pdo = DB::conn();
        foreach (['schema', 'seed'] as $sql) {
            $pdo->exec(file_get_contents(BASE_PATH . "/database/$sql.sql"));
        }
        paso('[OK] Base reinstalada desde schema.sql + seed.sql');
    }

    // Sesión inicial como administrador en la empresa 1
    actuarComo('admin', 1);

    if ((int) DB::valor('SELECT COUNT(*) FROM productos') > 0) {
        paso('[!] Ya existen productos cargados.');
        paso('    Use datos_demo.php?forzar=1 (o --forzar) para reinstalar y volver a cargar.');
        exit;
    }

    // =================================================================
    // 1. SEGUNDA EMPRESA (para probar el aislamiento)
    // =================================================================
    // Se crea con PEPS a propósito: así la demo muestra los dos criterios de
    // valorización conviviendo (la empresa 1 queda en promedio ponderado).
    $empresa2 = Empresa::guardar([
        'ruc' => '20512345678', 'razon_social' => 'Comercial Andina E.I.R.L.',
        'nombre_corto' => 'Comercial Andina', 'direccion' => 'Av. Los Álamos 455, Arequipa',
        'telefono' => '054-221100', 'email' => 'ventas@comercialandina.pe',
        'moneda' => 'PEN', 'simbolo' => 'S/',
        'metodo_valorizacion' => Valorizacion::PEPS,
    ]);
    paso("[OK] Empresa 2 creada (id=$empresa2) con valorización PEPS y sus catálogos base");

    // =================================================================
    // 2. USUARIOS DE TODOS LOS ROLES
    // =================================================================
    $usuarios = [
        ['almacen',  'Rosa Quispe Mamani',    'rosa@demo.pe',    3],  // ALMACENERO
        ['gerencia', 'Carlos Rivera Soto',    'carlos@demo.pe',  4],  // GERENCIA
        ['contab',   'Lucía Fernández Paz',   'lucia@demo.pe',   5],  // CONTABILIDAD
        ['jefe',     'Miguel Ángel Torres',   'miguel@demo.pe',  2],  // ADMINISTRADOR
    ];
    foreach ($usuarios as [$usr, $nom, $mail, $rol]) {
        Usuario::guardar([
            'usuario' => $usr, 'nombres' => $nom, 'email' => $mail,
            'rol_id' => $rol, 'clave' => 'demo123', 'estado' => 1,
        ]);
    }
    // Un usuario que trabaja en las DOS empresas, con rol distinto en cada una.
    Usuario::guardar([
        'usuario' => 'multi', 'nombres' => 'Ana Salazar (dos empresas)',
        'email' => 'ana@demo.pe', 'rol_id' => 3, 'clave' => 'demo123', 'estado' => 1,
    ]);
    $idAna = (int) DB::valor("SELECT id FROM usuarios WHERE usuario = 'multi'");
    DB::insertar('usuario_empresa', [
        'usuario_id' => $idAna, 'empresa_id' => $empresa2, 'rol_id' => 4, 'por_defecto' => 0,
    ]);
    paso('[OK] 5 usuarios creados (almacen, gerencia, contab, jefe, multi) — clave: demo123');

    // =================================================================
    // 3. CATÁLOGOS DE LA EMPRESA 1
    // =================================================================
    foreach ([
        ['Ferretería',  'Tornillería, fijaciones y accesorios'],
        ['Eléctricos',  'Cables, interruptores y material eléctrico'],
        ['Pinturas',    'Pinturas, solventes y acabados'],
        ['Herramientas','Herramientas manuales y eléctricas'],
        ['Seguridad',   'Equipos de protección personal'],
    ] as [$nom, $desc]) {
        asegurar('categorias', ['nombre' => $nom, 'descripcion' => $desc, 'estado' => 1]);
    }
    foreach (['Truper', 'Stanley', 'Indeco', 'Anypsa', 'Bosch', '3M'] as $m) {
        asegurar('marcas', ['nombre' => $m, 'estado' => 1]);
    }
    foreach ([['MT', 'Metro', 2], ['GLN', 'Galón', 2], ['JGO', 'Juego', 0], ['ROL', 'Rollo', 0]] as $u) {
        asegurar('unidades', ['codigo' => $u[0], 'nombre' => $u[1], 'decimales' => $u[2]]);
    }
    foreach ([
        ['20100047218', 'Distribuidora Ferretera del Sur S.A.C.', '054-234567', 'ventas@dfsur.pe',  'Av. Parra 1200, Arequipa'],
        ['20456789123', 'Importaciones Eléctricas Perú S.A.',     '01-4567890', 'contacto@iep.pe',  'Jr. Paruro 890, Lima'],
        ['10456123789', 'Pinturas y Acabados Andinos E.I.R.L.',   '054-445566', 'pedidos@paa.pe',   'Calle Mercaderes 220, Arequipa'],
    ] as $pv) {
        asegurar('proveedores', [
            'ruc' => $pv[0], 'razon_social' => $pv[1], 'telefono' => $pv[2],
            'email' => $pv[3], 'direccion' => $pv[4], 'estado' => 1,
        ]);
    }
    // Segundo almacén: permite probar el stock por almacén y los traslados.
    asegurar('almacenes', [
        'codigo' => 'ALM-02', 'nombre' => 'Almacén Sucursal Cayma',
        'direccion' => 'Av. Cayma 780, Arequipa', 'estado' => 1,
    ]);
    paso('[OK] Catálogos: categorías, marcas, unidades, 3 proveedores y 2 almacenes');

    $almPrincipal = cat('almacenes', 'Almacén Principal');
    $almSucursal  = cat('almacenes', 'Almacén Sucursal Cayma');

    // =================================================================
    // 4. PRODUCTOS
    // =================================================================
    $catalogo = [
        // codigo,     descripción,                              categoría,      marca,     unidad, compra, venta, mínimo
        ['TOR-1001', 'Tornillo hexagonal 1/2" x 3"',            'Ferretería',   'Truper',  'UND',   0.80,   1.50,  100],
        ['TOR-1002', 'Tornillo autorroscante 8 x 1"',           'Ferretería',   'Truper',  'UND',   0.35,   0.70,  200],
        ['TUE-1003', 'Tuerca hexagonal 1/2"',                   'Ferretería',   'Truper',  'UND',   0.45,   0.90,  150],
        ['CLA-1004', 'Clavo de acero 2 1/2"',                   'Ferretería',   'GENÉRICO','KG',    5.20,   8.50,   20],
        ['CAB-2001', 'Cable THW 14 AWG',                        'Eléctricos',   'Indeco',  'MT',    2.10,   3.50,  300],
        ['CAB-2002', 'Cable mellizo 2 x 18 AWG',                'Eléctricos',   'Indeco',  'MT',    1.75,   2.90,  250],
        ['INT-2003', 'Interruptor simple empotrar',             'Eléctricos',   'Bosch',   'UND',   6.50,  11.00,   40],
        ['TOM-2004', 'Tomacorriente doble con toma a tierra',   'Eléctricos',   'Bosch',   'UND',   8.90,  15.00,   40],
        ['FOC-2005', 'Foco LED 12W luz fría',                   'Eléctricos',   'GENÉRICO','UND',   7.20,  12.50,   60],
        ['PIN-3001', 'Pintura látex blanco',                    'Pinturas',     'Anypsa',  'GLN',  38.00,  62.00,   12],
        ['PIN-3002', 'Esmalte sintético negro',                 'Pinturas',     'Anypsa',  'GLN',  42.50,  69.00,   10],
        ['THI-3003', 'Thinner acrílico',                        'Pinturas',     'Anypsa',  'GLN',  18.00,  29.00,   15],
        ['BRO-3004', 'Brocha 4 pulgadas',                       'Pinturas',     'Truper',  'UND',   9.50,  16.00,   25],
        ['TAL-4001', 'Taladro percutor 1/2" 750W',              'Herramientas', 'Bosch',   'UND', 285.00, 420.00,    3],
        ['JGO-4002', 'Juego de destornilladores 6 piezas',      'Herramientas', 'Stanley', 'JGO',  45.00,  75.00,    8],
        ['MAR-4003', 'Martillo carpintero 16 oz',               'Herramientas', 'Stanley', 'UND',  32.00,  52.00,   10],
        ['CAS-5001', 'Casco de seguridad blanco',               'Seguridad',    '3M',      'UND',  25.00,  42.00,   20],
        ['GUA-5002', 'Guantes de badana (par)',                 'Seguridad',    '3M',      'UND',   8.50,  14.00,   50],
    ];
    foreach ($catalogo as $p) {
        Producto::guardar([
            'codigo' => $p[0], 'descripcion' => $p[1],
            'categoria_id' => cat('categorias', $p[2]),
            'marca_id'     => cat('marcas', $p[3]),
            'unidad_id'    => cat('unidades', $p[4]),
            'precio_compra' => $p[5], 'precio_venta' => $p[6],
            'stock_minimo'  => $p[7], 'estado' => 1,
        ]);
    }
    // Un producto descontinuado, para ver el filtro por estado.
    Producto::guardar([
        'codigo' => 'OBS-9001', 'descripcion' => 'Cinta aislante (descontinuado)',
        'categoria_id' => cat('categorias', 'Eléctricos'), 'marca_id' => cat('marcas', '3M'),
        'unidad_id' => cat('unidades', 'UND'), 'precio_compra' => 2.5, 'precio_venta' => 4.5,
        'stock_minimo' => 0, 'estado' => 0,
    ]);
    paso('[OK] 19 productos (18 activos + 1 descontinuado)');

    // =================================================================
    // 5-7. OPERACIÓN DEL ALMACÉN — UNA SOLA LÍNEA DE TIEMPO
    //
    // Las operaciones se ejecutan EN ORDEN CRONOLÓGICO, como habrían ocurrido
    // en la realidad. Es importante: el kardex guarda el saldo al momento de
    // registrar el movimiento, así que cargar primero todas las entradas y
    // después todas las salidas con fechas retroactivas dejaría una columna
    // de saldos incoherente al ordenar el historial por fecha.
    // Cada operación lleva su responsable, para que el reporte de movimientos
    // por usuario tenga sentido.
    // =================================================================
    actuarComo('almacen', 1);
    $provFer = cat('proveedores', 'Distribuidora Ferretera del Sur S.A.C.');
    $provEle = cat('proveedores', 'Importaciones Eléctricas Perú S.A.');
    $provPin = cat('proveedores', 'Pinturas y Acabados Andinos E.I.R.L.');

    $linea = [
        // --- Compras iniciales de abastecimiento ---
        [55, 'almacen', 'entrada', [$provFer, 'FACTURA', 'F001-004512', $almPrincipal, [
            ['TOR-1001', 800, 0.75], ['TOR-1002', 1500, 0.32], ['TUE-1003', 900, 0.42], ['CLA-1004', 120, 5.00]]]],
        [50, 'almacen', 'entrada', [$provEle, 'FACTURA', 'F002-001188', $almPrincipal, [
            ['CAB-2001', 1200, 2.05], ['CAB-2002', 800, 1.70], ['INT-2003', 150, 6.30], ['TOM-2004', 120, 8.60]]]],
        [45, 'jefe',    'entrada', [$provPin, 'FACTURA', 'F003-000341', $almPrincipal, [
            ['PIN-3001', 60, 37.00], ['PIN-3002', 40, 41.00], ['THI-3003', 50, 17.50], ['BRO-3004', 80, 9.20]]]],

        // --- Primeras ventas ---
        [40, 'almacen', 'salida', ['VENTA', 'Constructora Los Andes S.A.C.', $almPrincipal, [
            ['TOR-1001', 250], ['TUE-1003', 200], ['CAB-2001', 300]]]],
        [38, 'almacen', 'entrada', [$provFer, 'GUIA', 'G001-000876', $almPrincipal, [
            ['TAL-4001', 12, 280.00], ['JGO-4002', 30, 43.50], ['MAR-4003', 40, 31.00]]]],
        [35, 'multi',   'salida',  ['VENTA', 'Cliente mostrador', $almPrincipal, [
            ['PIN-3001', 18], ['BRO-3004', 22], ['THI-3003', 12]]]],
        // --- Reposición y más movimiento ---
        [30, 'almacen', 'entrada', [$provFer, 'FACTURA', 'F001-004698', $almPrincipal, [
            ['CAS-5001', 60, 24.00], ['GUA-5002', 200, 8.20], ['FOC-2005', 180, 7.00]]]],
        // El consumo va DESPUÉS de la compra: no se puede sacar lo que aún no entró.
        [29, 'almacen', 'salida',  ['CONSUMO', 'Área de mantenimiento', $almPrincipal, [
            ['GUA-5002', 40], ['CAS-5001', 12]]]],
        [28, 'multi',   'salida',  ['VENTA', 'Instalaciones Eléctricas RM', $almPrincipal, [
            ['CAB-2002', 350], ['INT-2003', 45], ['TOM-2004', 38]]]],
        [26, 'jefe',    'ajuste',  [$almPrincipal, 'CLA-1004', 'NEGATIVO', 3, 0,
            'Diferencia detectada en conteo parcial']],
        [22, 'almacen', 'salida',  ['MERMA', 'Producto deteriorado en bodega', $almPrincipal, [
            ['THI-3003', 4]]]],
        [20, 'jefe',    'ajuste',  [$almPrincipal, 'GUA-5002', 'POSITIVO', 12, 8.20,
            'Mercadería encontrada tras reordenar el almacén']],

        // --- Segunda compra del mismo producto a distinto precio ---
        // Es lo que hace visible el costo promedio ponderado en el kardex.
        [18, 'almacen', 'entrada', [$provEle, 'FACTURA', 'F002-001255', $almPrincipal, [
            ['CAB-2001', 600, 2.45], ['FOC-2005', 120, 7.90]]]],
        [16, 'multi',   'salida',  ['VENTA', 'Ferretería El Constructor', $almPrincipal, [
            ['TOR-1002', 700], ['CLA-1004', 45], ['MAR-4003', 12]]]],

        // --- Abastecimiento de la sucursal ---
        [15, 'almacen', 'entrada', [$provFer, 'GUIA', 'G001-000915', $almSucursal, [
            ['TOR-1001', 300, 0.78], ['MAR-4003', 15, 31.50], ['CAS-5001', 25, 24.50]]]],
        [12, 'almacen', 'salida',  ['CONSUMO', 'Taller de producción', $almPrincipal, [
            ['JGO-4002', 8], ['TAL-4001', 2]]]],
        [9,  'multi',   'salida',  ['VENTA', 'Constructora Los Andes S.A.C.', $almPrincipal, [
            ['CAB-2001', 900], ['FOC-2005', 150]]]],
        [7,  'almacen', 'entrada', [$provPin, 'BOLETA', 'B001-000077', $almPrincipal, [
            ['PIN-3001', 24, 38.50], ['BRO-3004', 40, 9.60]]]],
        [6,  'jefe',    'ajuste',  [$almPrincipal, 'INT-2003', 'NEGATIVO', 5, 0,
            'Unidades falladas dadas de baja']],
        [5,  'almacen', 'salida',  ['VENTA', 'Cliente mostrador', $almSucursal, [
            ['TOR-1001', 120], ['CAS-5001', 8]]]],
        [2,  'multi',   'salida',  ['DEVOLUCION', 'Devolución a proveedor', $almPrincipal, [
            ['PIN-3002', 5]]]],
    ];

    // Se ordena de lo más antiguo a lo más reciente antes de ejecutar.
    usort($linea, fn($a, $b) => $b[0] <=> $a[0]);

    $nEnt = $nSal = $nAju = 0;
    foreach ($linea as [$dias, $quien, $tipo, $datos]) {
        actuarComo($quien, 1);
        $fecha = hace($dias);

        if ($tipo === 'entrada') {
            [$prov, $tipoDoc, $nroDoc, $alm, $items] = $datos;
            Entrada::registrar([
                'fecha' => $fecha, 'almacen_id' => $alm, 'proveedor_id' => $prov,
                'tipo_documento' => $tipoDoc, 'nro_documento' => $nroDoc,
                'observacion' => 'Compra a proveedor',
            ], array_map(fn($i) => [
                'producto_id' => prod($i[0]), 'cantidad' => $i[1], 'costo_unitario' => $i[2],
            ], $items));
            $nEnt++;

        } elseif ($tipo === 'salida') {
            [$motivo, $destino, $alm, $items] = $datos;
            Salida::registrar([
                'fecha' => $fecha, 'almacen_id' => $alm,
                'motivo' => $motivo, 'destino' => $destino, 'observacion' => '',
            ], array_map(fn($i) => ['producto_id' => prod($i[0]), 'cantidad' => $i[1]], $items));
            $nSal++;

        } else {
            [$alm, $codigo, $signo, $cantidad, $costo, $motivo] = $datos;
            Ajuste::registrar([
                'fecha' => $fecha, 'almacen_id' => $alm, 'producto_id' => prod($codigo),
                'tipo' => $signo, 'cantidad' => $cantidad, 'costo_unitario' => $costo,
                'motivo' => $motivo,
            ]);
            $nAju++;
        }
    }
    paso("[OK] Operación cronológica: $nEnt entradas, $nSal salidas y $nAju ajustes en 55 días");
    paso('     (registradas por almacen, jefe y multi, en dos almacenes)');

    // =================================================================
    // 8. CASOS PARA LAS ALERTAS: stock mínimo y agotado
    // =================================================================
    // Se dejan por debajo del mínimo mediante salidas reales, no tocando el stock.
    $stockDe = function (string $codigo, int $alm) {
        return (float) DB::valor('SELECT COALESCE(cantidad,0) FROM stock WHERE producto_id = :p AND almacen_id = :a',
            [':p' => prod($codigo), ':a' => $alm]);
    };

    // TOM-2004 queda justo en el mínimo; PIN-3002 y JGO-4002 quedan agotados.
    $paraBajar = [
        ['TOM-2004', 40.0],   // mínimo 40 -> queda exactamente en el mínimo
        ['PIN-3002', 0.0],    // agotado
        ['JGO-4002', 0.0],    // agotado
        ['BRO-3004', 20.0],   // por debajo del mínimo (25)
    ];
    $items = [];
    foreach ($paraBajar as [$cod, $objetivo]) {
        $actual = $stockDe($cod, $almPrincipal);
        $sacar  = round($actual - $objetivo, 4);
        if ($sacar > 0) {
            $items[] = ['producto_id' => prod($cod), 'cantidad' => $sacar];
        }
    }
    if ($items) {
        Salida::registrar([
            'fecha' => hace(1), 'almacen_id' => $almPrincipal, 'motivo' => 'VENTA',
            'destino' => 'Pedido mayorista Constructora Sur',
            'observacion' => 'Deja productos en stock mínimo y agotados',
        ], $items);
    }
    paso('[OK] Alertas listas: productos en stock mínimo y productos agotados');

    // =================================================================
    // 9. INVENTARIO FÍSICO — uno cerrado y otro abierto
    // =================================================================
    // 9a. Conteo CERRADO en la sucursal, con diferencias ya conciliadas.
    $invCerrado = Inventario::abrir([
        'fecha' => hace(4), 'almacen_id' => $almSucursal,
        'observacion' => 'Conteo trimestral de la sucursal',
    ], true);
    $det = Inventario::buscar($invCerrado)['detalle'];
    $conteo = [];
    foreach ($det as $i => $d) {
        // Se cuenta todo: una línea sobra, otra falta y el resto coincide.
        $sistema = (float) $d['stock_sistema'];
        $conteo[$d['id']] = (string) match ($i) {
            0 => $sistema + 4,      // sobrante
            1 => max(0, $sistema - 3), // faltante
            default => $sistema,    // coincide
        };
    }
    Inventario::guardarConteo($invCerrado, $conteo);
    $rc = Inventario::cerrar($invCerrado);
    paso("[OK] Inventario físico CERRADO en la sucursal: {$rc['ajustes']} ajuste(s) generado(s)");

    // 9b. Conteo ABIERTO en el almacén principal, a medio contar.
    $invAbierto = Inventario::abrir([
        'fecha' => date('Y-m-d'), 'almacen_id' => $almPrincipal,
        'observacion' => 'Conteo mensual en curso — quedan líneas por registrar',
    ], true);
    $det = Inventario::buscar($invAbierto)['detalle'];
    $conteo = [];
    foreach (array_slice($det, 0, (int) ceil(count($det) * 0.6)) as $i => $d) {
        $sistema = (float) $d['stock_sistema'];
        $conteo[$d['id']] = (string) match ($i % 4) {
            0 => $sistema + 2,
            1 => max(0, $sistema - 5),
            default => $sistema,
        };
    }
    Inventario::guardarConteo($invAbierto, $conteo);
    paso('[OK] Inventario físico ABIERTO en el principal, contado al 60%');

    // =================================================================
    // 10. DATOS DE LA SEGUNDA EMPRESA (para comprobar el aislamiento)
    // =================================================================
    actuarComo('multi', $empresa2);
    asegurar('categorias', ['nombre' => 'Abarrotes', 'descripcion' => 'Productos de consumo', 'estado' => 1]);
    asegurar('marcas', ['nombre' => 'Gloria', 'estado' => 1]);
    asegurar('proveedores', [
        'ruc' => '20100123456', 'razon_social' => 'Mayorista Andina S.A.C.',
        'telefono' => '054-990011', 'email' => '', 'direccion' => '', 'estado' => 1,
    ]);
    $almE2 = cat('almacenes', 'Almacén Principal');
    // Mismo código de producto que en la empresa 1: debe convivir sin chocar.
    foreach ([
        ['TOR-1001', 'Leche evaporada 400g (otra empresa)', 3.20, 4.50, 24],
        ['ARZ-0001', 'Arroz extra saco 50kg',             165.00, 195.00, 5],
        ['ACE-0002', 'Aceite vegetal 1L',                   7.90,  10.50, 30],
    ] as $p) {
        Producto::guardar([
            'codigo' => $p[0], 'descripcion' => $p[1],
            'categoria_id' => cat('categorias', 'Abarrotes'),
            'marca_id' => cat('marcas', 'Gloria'),
            'unidad_id' => cat('unidades', 'UND'),
            'precio_compra' => $p[2], 'precio_venta' => $p[3],
            'stock_minimo' => $p[4], 'estado' => 1,
        ]);
    }
    Entrada::registrar([
        'fecha' => hace(10), 'almacen_id' => $almE2,
        'proveedor_id' => cat('proveedores', 'Mayorista Andina S.A.C.'),
        'tipo_documento' => 'FACTURA', 'nro_documento' => 'F100-000021', 'observacion' => 'Compra inicial',
    ], [
        ['producto_id' => prod('TOR-1001'), 'cantidad' => 240, 'costo_unitario' => 3.10],
        ['producto_id' => prod('ARZ-0001'), 'cantidad' => 40,  'costo_unitario' => 162.00],
        ['producto_id' => prod('ACE-0002'), 'cantidad' => 300, 'costo_unitario' => 7.75],
    ]);
    // Segunda compra más cara: con PEPS la venta siguiente saldrá al costo
    // de la primera compra, no al último. Es lo que hace visible el método.
    Entrada::registrar([
        'fecha' => hace(6), 'almacen_id' => $almE2,
        'proveedor_id' => cat('proveedores', 'Mayorista Andina S.A.C.'),
        'tipo_documento' => 'FACTURA', 'nro_documento' => 'F100-000034', 'observacion' => 'Reposición',
    ], [
        ['producto_id' => prod('ACE-0002'), 'cantidad' => 200, 'costo_unitario' => 8.60],
        ['producto_id' => prod('ARZ-0001'), 'cantidad' => 20,  'costo_unitario' => 171.00],
    ]);
    Salida::registrar([
        'fecha' => hace(3), 'almacen_id' => $almE2, 'motivo' => 'VENTA',
        'destino' => 'Bodega San Martín', 'observacion' => '',
    ], [
        ['producto_id' => prod('ACE-0002'), 'cantidad' => 120],
        ['producto_id' => prod('ARZ-0001'), 'cantidad' => 12],
    ]);
    paso('[OK] Empresa 2 (PEPS) con sus propios productos y dos compras a distinto precio');
    paso('     incluye el código TOR-1001 repetido a propósito, para probar el aislamiento');

    // =================================================================
    // RESUMEN
    // =================================================================
    actuarComo('admin', 1);
    $r = Reporte::resumen();

    paso('');
    paso('============================================================');
    paso('  DATOS DE DEMOSTRACIÓN CARGADOS');
    paso('============================================================');
    paso('');
    paso('  Empresa 1 — Empresa Demo S.A.C.');
    paso('    Productos activos ....... ' . $r['productos']);
    paso('    En stock mínimo ......... ' . $r['stock_minimo']);
    paso('    Agotados ................ ' . $r['agotados']);
    paso('    Inventario valorizado ... S/ ' . number_format($r['valor_total'], 2));
    paso('    Entradas ................ ' . DB::valor('SELECT COUNT(*) FROM entradas WHERE empresa_id = 1'));
    paso('    Salidas ................. ' . DB::valor('SELECT COUNT(*) FROM salidas  WHERE empresa_id = 1'));
    paso('    Movimientos en kardex ... ' . DB::valor('SELECT COUNT(*) FROM kardex   WHERE empresa_id = 1'));
    paso('    Registros de auditoría .. ' . DB::valor('SELECT COUNT(*) FROM auditoria WHERE empresa_id = 1'));
    paso('');
    paso('  Usuarios (todos con clave: demo123, salvo admin)');
    paso('    admin    / admin123  SUPERADMIN     ve las dos empresas');
    paso('    jefe     / demo123   ADMINISTRADOR  todo dentro de su empresa');
    paso('    almacen  / demo123   ALMACENERO     registra entradas, salidas y conteos');
    paso('    gerencia / demo123   GERENCIA       sólo consulta (sin valorizado)');
    paso('    contab   / demo123   CONTABILIDAD   consulta con reportes valorizados');
    paso('    multi    / demo123   ALMACENERO en Empresa Demo y GERENCIA en Comercial Andina');
    paso('');
    paso('  Qué probar');
    paso('    · Panel: alertas de stock mínimo y agotados');
    paso('    · Productos: buscar "cable", filtrar por categoría y por estado Inactivo');
    paso('    · Kardex de CAB-2001: dos compras a distinto precio mueven el costo promedio');
    paso('    · Salidas: intente sacar más de lo disponible, debe rechazarlo');
    paso('    · Inventario físico: hay uno CERRADO y otro ABIERTO a medio contar');
    paso('    · Reportes: filtre por fechas (hay movimientos de los últimos 60 días)');
    paso('    · Exporte cualquier reporte a PDF y a Excel');
    paso('    · Importe productos con la plantilla de productos_importar.php');
    paso('    · Entre con "multi" y cambie de empresa: el rol y los permisos cambian');
    paso('    · Auditoría: revise el historial de todo lo anterior');
    paso('');
    paso('  Recuerde eliminar datos_demo.php e instalar.php antes de publicar.');

} catch (Throwable $e) {
    http_response_code(500);
    paso('');
    paso('[ERROR] ' . $e->getMessage());
    paso('        ' . $e->getFile() . ':' . $e->getLine());
}
