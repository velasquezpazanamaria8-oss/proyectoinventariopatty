<?php
/**
 * El lienzo: colocar a mano los bloques de la cotización de esta empresa.
 *
 * Es la alternativa al formulario de opciones para las empresas que quieren
 * las cosas en otro sitio. Lo que se compone aquí sólo se usa si se marca
 * «usar este diseño»; mientras no se marque, el PDF sigue saliendo del modo
 * simple y se puede trastear sin miedo.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('cotizaciones.gestionar');

// La foto de una firma escaneada vive en storage/, cerrado al navegador por
// .htaccess (igual que el logo): se sirve desde aquí, comprobando que la
// ruta pedida sea de verdad una imagen de firma y no cualquier otra cosa.
if (($_GET['a'] ?? '') === 'firma') {
    $ruta = (string) ($_GET['r'] ?? '');
    $abs  = $ruta !== '' ? realpath(BASE_PATH . '/' . $ruta) : false;
    $raiz = realpath(BASE_PATH . '/storage/firmas');

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

// Si lo que llega pasa de `post_max_size`, PHP vacía $_POST y $_FILES enteros
// y sin avisar por qué: $_POST['a'] deja de existir, la petición no entra a
// ningún bloque de abajo, y sigue de largo hasta el guardado normal del
// lienzo, que vería un POST vacío y grabaría el diseño SIN bloques. Se corta
// aquí antes de que eso pase, tanto para la imagen (AJAX) como para el
// guardado de todo el lienzo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $esImagen = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
    $msg = ($esImagen ? 'La imagen es demasiado grande' : 'Lo que se intentó guardar es demasiado grande')
        . ' para este servidor (límite actual: ' . ini_get('post_max_size') . ').';
    if ($esImagen) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg . ' Pruebe con una imagen más liviana.']);
        exit;
    }
    Sesion::flash('error', $msg);
    Vista::redirigir('cotizacion_lienzo.php');
}

// Subida de una imagen de firma desde el lienzo (AJAX): a diferencia del
// logo, no hay "la" firma de la empresa, sino una por cada bloque que se
// suba, así que responde con la ruta en vez de redirigir a la pantalla.
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['a'] ?? '') === 'subir_firma')) {
    header('Content-Type: application/json; charset=utf-8');
    if (!Csrf::valido($_POST['_csrf'] ?? null)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'La sesión del formulario expiró. Recargue la página.']);
        exit;
    }
    try {
        $archivo = $_FILES['imagen'] ?? null;
        if (!$archivo) {
            throw new RuntimeException('No llegó ninguna imagen.');
        }
        $ruta = CotizacionDiseno::guardarImagenFirma($archivo);
        echo json_encode(['ok' => true, 'ruta' => $ruta,
            'url' => url('cotizacion_lienzo.php?a=firma&r=' . urlencode($ruta))]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$cfg = CotizacionConfig::actual();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    try {
        if (($_POST['a'] ?? '') === 'restaurar') {
            // Vuelve a los bloques que reproducen el diseño simple de esta
            // empresa: la salida de un lienzo en el que uno se perdió.
            CotizacionConfig::guardarDiseno(
                CotizacionDiseno::porDefecto($cfg), 250, $cfg['modo'] === 'LIBRE');
            Sesion::flash('ok', 'Se restauró la disposición de fábrica.');
        } else {
            $bloques = json_decode((string) ($_POST['bloques'] ?? '[]'), true);
            CotizacionConfig::guardarDiseno(
                is_array($bloques) ? $bloques : [],
                (int) ($_POST['alto_cabecera'] ?? 250),
                !empty($_POST['libre']));
            Sesion::flash('ok', !empty($_POST['libre'])
                ? 'Diseño guardado. Las cotizaciones de esta empresa ya salen con él.'
                : 'Diseño guardado. Sigue emitiéndose con el modo simple hasta que marque «usar este diseño».');
        }
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('cotizacion_lienzo.php');
}

// Los bloques se dibujan con datos de muestra: el lienzo tiene que enseñar
// texto de verdad para poder juzgar si algo cabe o se sale.
$muestra = CotizacionDiseno::ejemplo();
$empresa = Empresa::ficha();

$valores = [];
foreach (CotizacionDiseno::claves() as $clave) {
    $valores[$clave] = CotizacionDiseno::valor($clave, $muestra, $empresa, $cfg);
}

Vista::render('cotizaciones/lienzo', [
    'cfg'      => $cfg,
    'muestra'  => $muestra,
    'empresa'  => $empresa,
    'valores'  => $valores,
    'datos'    => CotizacionDiseno::DATOS,
    'piezas'   => CotizacionDiseno::PIEZAS,
], 'Lienzo de la cotización');
