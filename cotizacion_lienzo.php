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
    'empresa'  => $empresa,
    'valores'  => $valores,
    'datos'    => CotizacionDiseno::DATOS,
    'piezas'   => CotizacionDiseno::PIEZAS,
], 'Lienzo de la cotización');
