<?php
/**
 * Genera un volcado .sql con ESTRUCTURA + DATOS listo para importar en el
 * hosting (phpMyAdmin de Hostinger, por ejemplo).
 *
 *   php database/exportar_demo.php
 *   php database/exportar_demo.php --clave=MiClaveSegura   (cambia la de admin)
 *   php database/exportar_demo.php --salida=otro_nombre.sql
 *
 * Por qué no se usa mysqldump: en este equipo es el de MySQL 8, y los hostings
 * compartidos suelen correr MariaDB, que rechaza parte de su sintaxis. Aquí la
 * estructura sale del propio schema.sql del proyecto —escrito para MariaDB— y
 * sólo los datos se leen de la base, así que el archivo importa en ambos.
 */

require_once __DIR__ . '/../bootstrap.php';

$args   = $argv ?? [];
$clave  = null;
$salida = __DIR__ . '/kardex_demo.sql';

foreach ($args as $a) {
    if (str_starts_with($a, '--clave='))  { $clave  = substr($a, 8); }
    if (str_starts_with($a, '--salida=')) { $salida = __DIR__ . '/' . basename(substr($a, 9)); }
}

/**
 * Orden de volcado: respeta las claves foráneas para que el archivo pueda
 * importarse de arriba hacia abajo sin errores.
 */
const TABLAS = [
    'empresas', 'roles', 'permisos', 'rol_permiso', 'usuarios', 'usuario_empresa',
    'categorias', 'marcas', 'unidades', 'proveedores', 'almacenes',
    'productos', 'stock',
    'entradas', 'entrada_detalle', 'salidas', 'salida_detalle',
    'kardex', 'capas_costo', 'kardex_capa',
    'ajustes', 'inventarios', 'inventario_detalle', 'auditoria',
];

function valorSql(PDO $pdo, $v): string
{
    if ($v === null)   return 'NULL';
    if (is_int($v))    return (string) $v;
    if (is_float($v))  return rtrim(rtrim(sprintf('%.4F', $v), '0'), '.');
    if (is_bool($v))   return $v ? '1' : '0';
    return $pdo->quote((string) $v);
}

try {
    $pdo = DB::conn();

    // Contraseña de demostración: se puede fijar una distinta al exportar.
    if ($clave !== null) {
        if (strlen($clave) < 6) {
            throw new RuntimeException('La clave debe tener al menos 6 caracteres.');
        }
        DB::actualizar('usuarios', ['password_hash' => password_hash($clave, PASSWORD_BCRYPT)],
            "usuario = 'admin'");
        echo "[OK] Contraseña de 'admin' cambiada para este volcado." . PHP_EOL;
    }

    $sql  = "-- ============================================================\n";
    $sql .= "-- Sistema de Kardex e Inventarios — base de demostración\n";
    $sql .= "-- Estructura + datos, generado el " . date('d/m/Y H:i') . "\n";
    $sql .= "--\n";
    $sql .= "-- Importar en phpMyAdmin sobre una base de datos VACÍA.\n";
    $sql .= "-- Compatible con MySQL 5.7+ y MariaDB 10.4+.\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
    $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

    // --- Estructura: el schema.sql del proyecto, sin sus SET propios ---
    $estructura = file_get_contents(BASE_PATH . '/database/schema.sql');
    $estructura = preg_replace('/^SET (NAMES|FOREIGN_KEY_CHECKS).*$/mi', '', $estructura);
    $sql .= "-- ------------------------------------------------------------\n";
    $sql .= "-- ESTRUCTURA\n";
    $sql .= "-- ------------------------------------------------------------\n";
    $sql .= trim($estructura) . "\n\n";

    // --- Datos ---
    $sql .= "-- ------------------------------------------------------------\n";
    $sql .= "-- DATOS\n";
    $sql .= "-- ------------------------------------------------------------\n\n";

    $totalFilas = 0;
    foreach (TABLAS as $tabla) {
        $filas = DB::todos("SELECT * FROM `$tabla`");
        echo sprintf("  %-20s %5d fila(s)%s", $tabla, count($filas), PHP_EOL);
        if (!$filas) {
            continue;
        }
        $totalFilas += count($filas);

        $cols = array_map(fn($c) => "`$c`", array_keys($filas[0]));
        $sql .= "-- $tabla\n";

        // Se agrupan de 50 en 50: importa más rápido y evita paquetes enormes.
        foreach (array_chunk($filas, 50) as $lote) {
            $valores = [];
            foreach ($lote as $f) {
                $valores[] = '(' . implode(',', array_map(fn($v) => valorSql($pdo, $v), array_values($f))) . ')';
            }
            $sql .= "INSERT INTO `$tabla` (" . implode(',', $cols) . ") VALUES\n"
                 . implode(",\n", $valores) . ";\n";
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    file_put_contents($salida, $sql);

    echo PHP_EOL;
    echo "[OK] Volcado generado: " . $salida . PHP_EOL;
    echo "     " . number_format(strlen($sql) / 1024, 1) . " KB · " . $totalFilas . " filas · "
       . count(TABLAS) . " tablas" . PHP_EOL;

} catch (Throwable $e) {
    http_response_code(500);
    echo '[ERROR] ' . $e->getMessage() . PHP_EOL;
}
