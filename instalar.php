<?php
/**
 * Instalador: crea la base de datos, las tablas y los datos iniciales.
 * Ejecutar una sola vez y luego ELIMINAR este archivo.
 *
 *   Navegador: http://localhost/proyectoinventariopatty/instalar.php
 *   Consola:   php instalar.php
 */
$esCli = PHP_SAPI === 'cli';
if (!$esCli) { header('Content-Type: text/plain; charset=utf-8'); }

$config = require __DIR__ . '/config.php';
$db = $config['db'];

function paso(string $txt): void { echo $txt . PHP_EOL; }

try {
    $pdo = new PDO(
        "mysql:host={$db['host']};port={$db['puerto']};charset={$db['charset']}",
        $db['usuario'], $db['clave'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['nombre']}`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    paso("[OK] Base de datos '{$db['nombre']}' lista.");

    $pdo->exec("USE `{$db['nombre']}`");

    $yaInstalado = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = '{$db['nombre']}' AND table_name = 'kardex'"
    )->fetchColumn();

    if ($yaInstalado && !in_array('--forzar', $argv ?? [], true) && !isset($_GET['forzar'])) {
        paso('[!] El sistema ya está instalado.');
        paso('    Para reinstalar y BORRAR TODOS LOS DATOS use: instalar.php?forzar=1  (o --forzar en consola)');
        exit;
    }

    foreach (['schema', 'seed'] as $archivo) {
        $sql = file_get_contents(__DIR__ . "/database/$archivo.sql");
        $pdo->exec($sql);
        paso("[OK] $archivo.sql ejecutado.");
    }

    paso('');
    paso('=== Instalación completada ===');
    paso('Usuario: admin');
    paso('Clave:   admin123');
    paso('');
    paso('IMPORTANTE: cambie la contraseña e ELIMINE instalar.php antes de publicar el sistema.');

} catch (Throwable $e) {
    http_response_code(500);
    paso('[ERROR] ' . $e->getMessage());
}
