<?php
/**
 * Diagnóstico rápido y de solo lectura del cron: últimas tareas de TODAS
 * las empresas (sin depender de cuál esté activa) y hora del servidor.
 * Se protege con la misma clave que cron.php porque también es pública.
 *
 * Uso: https://sudominio.com/diagnostico_cron.php?clave=LA-MISMA-CLAVE
 * Borrar este archivo cuando ya no se necesite: no debe quedar en producción.
 */
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$esperada = (string) Config::get('app.cron_clave', '');
$recibida = (string) ($_GET['clave'] ?? '');
if ($esperada === '' || strlen($esperada) < 16 || !hash_equals($esperada, $recibida)) {
    http_response_code(403);
    exit("No autorizado.\n");
}

echo "Hora del servidor (zona app): " . date('Y-m-d H:i:s T') . "\n";
echo "Hora del servidor (UTC):      " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

echo "=== Empresas con credenciales SUNAT ===\n";
$empresas = DB::todos(
    'SELECT e.id, e.nombre_corto FROM credenciales_sunat c
       JOIN empresas e ON e.id = c.empresa_id
      WHERE e.estado = 1 ORDER BY e.id');
if (!$empresas) {
    echo "(ninguna)\n";
}
foreach ($empresas as $e) {
    echo "  #{$e['id']}  {$e['nombre_corto']}\n";
}

echo "\n=== Últimas 30 tareas (sunat_tareas), todas las empresas ===\n";
$tareas = DB::todos(
    'SELECT t.id, e.nombre_corto, t.origen, t.estado, t.resumen, t.iniciado_en, t.terminado_en
       FROM sunat_tareas t
       JOIN empresas e ON e.id = t.empresa_id
      ORDER BY t.id DESC LIMIT 30');
if (!$tareas) {
    echo "(no hay ninguna tarea registrada todavia)\n";
}
foreach ($tareas as $t) {
    printf(
        "#%-4d %-15s %-8s %-9s  ini:%s  fin:%s  %s\n",
        $t['id'], $t['nombre_corto'], $t['origen'], $t['estado'],
        $t['iniciado_en'], $t['terminado_en'] ?? '(sin terminar)',
        $t['resumen'] ?? ''
    );
}
