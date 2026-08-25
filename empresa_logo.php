<?php
/**
 * Logo de una empresa, para la portada de selección.
 *
 * La portada se ve ANTES de entrar a ninguna empresa, así que no sirve el
 * filtro habitual por empresa activa: se comprueba que el usuario tenga acceso
 * a esa empresa en concreto. Los logos viven en storage/, cerrado al navegador.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requiereLogin();

$id = (int) ($_GET['id'] ?? 0);

$permitida = false;
foreach (Empresa::delUsuario(Auth::id()) as $e) {
    if ((int) $e['id'] === $id) {
        $permitida = true;
        break;
    }
}
if (!$permitida) {
    http_response_code(404);
    exit;
}

$ruta = DB::valor('SELECT logo_ruta FROM cotizacion_config WHERE empresa_id = :e', [':e' => $id]);
$abs  = $ruta ? realpath(BASE_PATH . '/' . $ruta) : false;
$raiz = realpath(BASE_PATH . '/storage/logos');

if (!$abs || !$raiz || !str_starts_with($abs, $raiz) || !is_file($abs)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($abs);
