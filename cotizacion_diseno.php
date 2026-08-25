<?php
/**
 * Diseño de la cotización de la empresa activa.
 *
 * Cada empresa del grupo guarda aquí lo suyo —logo, color, columnas, textos y
 * cuentas— para que su cotización no se parezca a la de las demás sin tener que
 * mantener una plantilla distinta por empresa.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('cotizaciones.gestionar');

// El logo vive en storage/, que está cerrado al navegador por .htaccess. Se
// sirve desde aquí para que siga fuera del alcance directo y sólo lo vea quien
// tiene abierta esa empresa.
if (($_GET['a'] ?? '') === 'logo') {
    $cfg = CotizacionConfig::actual();
    $abs = !empty($cfg['logo_ruta']) ? realpath(BASE_PATH . '/' . $cfg['logo_ruta']) : false;
    $raiz = realpath(BASE_PATH . '/storage/logos');

    if (!$abs || !$raiz || !str_starts_with($abs, $raiz) || !is_file($abs)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    try {
        $logo = ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            ? $_FILES['logo'] : null;
        CotizacionConfig::guardar($_POST, $logo);
        Sesion::flash('ok', 'Diseño guardado.' . ($logo ? ' El logo se preparó para el PDF.' : ''));
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('cotizacion_diseno.php');
}

Vista::render('cotizaciones/diseno', [
    'cfg'     => CotizacionConfig::actual(),
    'empresa' => Empresa::actual(),
    'campos'  => CotizacionConfig::CAMPOS,
], 'Diseño de cotización');
