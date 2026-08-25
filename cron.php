<?php
/**
 * Punto de entrada de las tareas programadas.
 *
 * Hostinger permite ejecutarlas por URL o por línea de comandos:
 *
 *   URL:      https://sudominio.com/cron.php?clave=LA-CLAVE
 *   Consola:  /usr/bin/php /home/uXXXX/public_html/cron.php
 *
 * Recorre TODAS las empresas que tengan credenciales, dedicando a cada una un
 * presupuesto de tiempo. Trae del SIRE y descarga comprobantes; no genera
 * movimientos de inventario (eso exige revisión humana).
 *
 * La clave sale de config.php (`app.cron_clave`). Por consola no se pide,
 * porque quien puede ejecutar PHP en el servidor ya tiene acceso.
 */
require_once __DIR__ . '/bootstrap.php';

$esCli = PHP_SAPI === 'cli';
if (!$esCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

// --- Autenticación ---
if (!$esCli) {
    $esperada = (string) Config::get('app.cron_clave', '');
    $recibida = (string) ($_GET['clave'] ?? '');

    if ($esperada === '' || strlen($esperada) < 16) {
        http_response_code(500);
        exit("No hay clave de cron configurada (app.cron_clave en config.php).\n"
           . "Debe tener al menos 16 caracteres: esta URL es pública.\n");
    }
    if (!hash_equals($esperada, $recibida)) {
        // Sin pistas sobre por qué falló, y sin registrar la clave recibida.
        http_response_code(403);
        exit("No autorizado.\n");
    }
}

$segundos = max(30, min(600, (int) ($_GET['segundos'] ?? ($argv[1] ?? 240))));
$soloEmpresa = (int) ($_GET['empresa'] ?? 0);

echo '[' . date('Y-m-d H:i:s') . '] Iniciando pasada (presupuesto ' . $segundos . 's por empresa)' . PHP_EOL;

// El cron no tiene sesión de navegador: se necesita un usuario para que los
// movimientos y la auditoría queden atribuidos a alguien.
$usuario = DB::uno(
    'SELECT u.* FROM usuarios u
       JOIN usuario_empresa ue ON ue.usuario_id = u.id
       JOIN roles r ON r.id = ue.rol_id
      WHERE u.estado = 1 AND r.global = 1
      ORDER BY u.id LIMIT 1');

if (!$usuario) {
    exit("No hay ningún usuario con rol global para atribuir la ejecución.\n");
}
Sesion::set('usuario', [
    'id' => (int) $usuario['id'], 'usuario' => $usuario['usuario'], 'nombres' => $usuario['nombres'],
]);

$empresas = SunatTarea::empresasConCredenciales();
if (!$empresas) {
    exit("Ninguna empresa tiene credenciales SUNAT configuradas.\n");
}

foreach ($empresas as $emp) {
    if ($soloEmpresa && (int) $emp['id'] !== $soloEmpresa) {
        continue;
    }
    if (!Empresa::activar((int) $emp['id'], (int) $usuario['id'])) {
        echo "  {$emp['nombre_corto']}: sin acceso, se omite" . PHP_EOL;
        continue;
    }

    $ini = microtime(true);
    $r = SunatTarea::ejecutar($segundos, 'cron');
    printf("  %-22s %s  (%.0fs)%s", $emp['nombre_corto'],
        ($r['ok'] ? 'OK ' : 'AVISO ') . $r['mensaje'], microtime(true) - $ini, PHP_EOL);
}

echo '[' . date('Y-m-d H:i:s') . '] Pasada terminada' . PHP_EOL;
