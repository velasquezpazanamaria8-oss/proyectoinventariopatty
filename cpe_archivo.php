<?php
/**
 * Sirve el XML, el PDF o el CDR ya descargado de un comprobante.
 *
 * Está aparte de las pantallas porque lo usan varias (descargas y conciliación)
 * y porque los archivos viven en storage/, fuera del alcance del navegador: éste
 * es el único camino hasta ellos, y por eso comprueba permiso y empresa.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('sunat.gestionar');

/**
 * Corta con un mensaje legible: esto puede abrirse dentro de un iframe, y ahí
 * una página en blanco no dice nada. Escapa con htmlspecialchars y no con e(),
 * porque ese atajo vive en Vista.php y aquí puede no haberse cargado todavía.
 */
$salir = function (int $codigo, string $mensaje): never {
    http_response_code($codigo);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Archivo no disponible</title>'
        . '<body style="font:14px system-ui;padding:24px;color:#334">'
        . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '</body>';
    exit;
};

$cpe = DB::uno('SELECT * FROM sunat_comprobantes WHERE id = :id AND ' . Empresa::filtro(),
    Empresa::param() + [':id' => (int) ($_GET['id'] ?? 0)]);
if (!$cpe) {
    $salir(404, 'Ese comprobante no es de la empresa activa.');
}

$clase = in_array($_GET['t'] ?? '', ['xml', 'pdf', 'cdr'], true) ? $_GET['t'] : 'xml';
$rel   = $cpe[$clase . '_path'] ?? null;
$abs   = $rel ? realpath(BASE_PATH . '/' . $rel) : false;

// La ruta sale de la base, no del navegador, pero se comprueba igual que caiga
// dentro de storage/cpe: si algún día se guardara una ruta mal formada, esto
// impide servir cualquier otro archivo del servidor.
$raiz = realpath(BASE_PATH . '/storage/cpe');
if (!$abs || !$raiz || !str_starts_with($abs, $raiz) || !is_file($abs)) {
    $salir(404, 'Ese archivo todavía no está descargado ('
        . strtoupper($clase) . ' de ' . $cpe['serie'] . '-' . $cpe['numero'] . ').');
}

// El PDF se muestra; el XML y el CDR se descargan, que es lo útil: el navegador
// no sabe enseñarlos y son para el contador.
$verEnPantalla = $clase === 'pdf' && !isset($_GET['bajar']);

header('Content-Type: ' . ($clase === 'pdf' ? 'application/pdf' : 'application/xml; charset=utf-8'));
header('Content-Disposition: ' . ($verEnPantalla ? 'inline' : 'attachment')
    . '; filename="' . basename($abs) . '"');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
readfile($abs);
