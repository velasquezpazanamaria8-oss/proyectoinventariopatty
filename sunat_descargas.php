<?php
/**
 * Descarga de comprobantes: baja XML/PDF y extrae las líneas de producto.
 * Sigue sin tocar el inventario: eso es la conciliación (fase siguiente).
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('sunat.gestionar');

if (!CredencialSunat::existe()) {
    Sesion::flash('warning', 'Primero configure las credenciales SUNAT.');
    Vista::redirigir('sunat.php');
}

$periodos = SunatComprobante::periodosSincronizados();
if (!$periodos) {
    Sesion::flash('warning', 'Primero traiga un período del SIRE.');
    Vista::redirigir('sunat_comprobantes.php');
}

$periodo = preg_replace('/\D/', '', (string) ($_GET['periodo'] ?? array_key_first($periodos)));

// Reintento forzado de los comprobantes que agotaron sus intentos.
//
// La descarga se rinde tras 3 intentos para no machacar a SUNAT con algo que
// falla una y otra vez. El problema es que entonces no queda nada "por
// intentar", el botón se desactiva y el período parece terminado con errores
// que sí eran recuperables. Esto pone el contador a cero y permite insistir
// cuando el usuario lo decide.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    if (($_POST['op'] ?? '') === 'reintentar') {
        $n = SunatCpeItem::reintentar($periodo);
        Sesion::flash($n ? 'ok' : 'warning', $n
            ? "Se habilitaron $n comprobante(s) para volver a intentarlo. Pulse «Descargar pendientes»."
            : 'No había comprobantes fallidos que reintentar.');
    }

    // Carga manual del XML, para lo que SUNAT no entrega por API.
    if (($_POST['op'] ?? '') === 'subir_xml') {
        try {
            Sesion::flash('ok', SubirCpe::procesar(
                (int) ($_POST['id'] ?? 0), $_FILES['archivo'] ?? null));
        } catch (Throwable $e) {
            Sesion::flash('error', $e->getMessage());
        }
    }

    Vista::redirigir('sunat_descargas.php?periodo=' . $periodo);
}

// Los archivos ya guardados los sirve cpe_archivo.php, que comparten esta
// pantalla y la de conciliación. Se mantiene el enlace antiguo por si alguien
// lo tenía guardado.
if (($_GET['a'] ?? '') === 'archivo') {
    Vista::redirigir('cpe_archivo.php?id=' . (int) ($_GET['id'] ?? 0)
        . '&t=' . urlencode((string) ($_GET['t'] ?? 'xml'))
        . (isset($_GET['bajar']) ? '&bajar=1' : ''));
}

$avance = SunatCpeItem::avance($periodo);

// Si quedó una descarga a medias, la pantalla la retoma sola al abrirse. Sólo
// tiene sentido cuando de verdad queda algo que bajar: una marca sobre un
// período ya terminado se retira aquí, para no anunciar que continúa algo que
// no tiene nada que hacer.
$enMarcha = DescargaEnMarcha::activa($periodo);
if ($enMarcha && $avance['por_intentar'] === 0) {
    DescargaEnMarcha::quitar($periodo);
    $enMarcha = false;
}

Vista::render('sunat/descargas', [
    'periodos'  => $periodos,
    'periodo'   => $periodo,
    'avance'    => $avance,
    'enMarcha'  => $enMarcha,
    // En las ventas el emisor es la propia empresa; hace falta para poder
    // buscar el comprobante en el portal de SUNAT.
    'rucPropio' => (string) (CredencialSunat::deEmpresa()['ruc'] ?? ''),
    // Comprobantes con archivo, para poder abrirlos desde la pantalla.
    'archivos'  => DB::todos(
        'SELECT id, tipo, cod_tipo_cdp, serie, numero, fecha_emision, ruc_contraparte,
                nombre_contraparte, total, items_cant, xml_path, pdf_path, cdr_path, descarga_msg
           FROM sunat_comprobantes
          WHERE ' . Empresa::filtro() . ' AND periodo = :p
            AND (xml_path IS NOT NULL OR pdf_path IS NOT NULL)
          ORDER BY fecha_emision DESC, id DESC LIMIT 300',
        Empresa::param() + [':p' => $periodo]),
    'distintos' => SunatCpeItem::distintos(['periodo' => $periodo]),
    'items'     => SunatCpeItem::listar(['periodo' => $periodo], 300),
    'conErrores' => DB::todos(
        'SELECT id, serie, numero, tipo, cod_tipo_cdp, ruc_contraparte,
                nombre_contraparte, fecha_emision, total, descarga_msg
           FROM sunat_comprobantes
          WHERE ' . Empresa::filtro() . ' AND periodo = :p AND descarga_estado = \'ERROR\'
          ORDER BY id LIMIT 50',
        Empresa::param() + [':p' => $periodo]),
], 'Descarga de comprobantes');
