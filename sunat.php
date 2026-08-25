<?php
/**
 * Conexión con SUNAT: credenciales de la empresa activa, prueba de conexión y
 * alta automática de los permisos de la API.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('sunat.gestionar');

$resultado = null;   // salida de la última acción, para mostrarla en pantalla

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    $op = $_POST['op'] ?? '';

    try {
        if ($op === 'guardar') {
            CredencialSunat::guardar($_POST);
            Sesion::flash('ok', 'Credenciales guardadas. Los secretos quedaron cifrados.');
            Vista::redirigir('sunat.php');
        }

        if ($op === 'eliminar') {
            CredencialSunat::eliminar();
            Sesion::flash('ok', 'Credenciales eliminadas.');
            Vista::redirigir('sunat.php');
        }

        if ($op === 'probar') {
            $r = SunatApi::probar(CredencialSunat::descifradas());
            CredencialSunat::anotarPrueba($r['ok'], $r['mensaje'], $r['recursos']);
            Auditoria::registrar('PROBAR', 'credenciales_sunat', null,
                ['ok' => $r['ok'], 'sire' => $r['sire'], 'cpe' => $r['cpe']]);
            $resultado = ['tipo' => 'prueba'] + $r;
        }

        if ($op === 'permisos') {
            // Toca la configuración real del contribuyente en SUNAT: se deja
            // constancia en auditoría de lo que se agregó.
            $r = SunatPermisos::habilitar(CredencialSunat::descifradas());
            Auditoria::registrar('PERMISOS_SUNAT', 'credenciales_sunat', null, [
                'agregados' => $r['agregados'], 'perdidas' => $r['perdidas'],
            ]);
            $resultado = ['tipo' => 'permisos'] + $r;
            if ($r['perdidas']) {
                Sesion::flash('error', 'Se habilitaron los recursos pero desaparecieron rutas que antes '
                    . 'estaban: ' . implode(', ', $r['perdidas']) . '. Revíselo en el portal SOL.');
            }
        }
    } catch (Throwable $e) {
        // El mensaje puede venir de SUNAT: se muestra tal cual, sin secretos.
        Sesion::flash('error', $e->getMessage());
        if ($op === 'probar') {
            CredencialSunat::anotarPrueba(false, $e->getMessage());
        }
    }
}

Vista::render('sunat/credenciales', [
    'cred'      => CredencialSunat::deEmpresa(),
    'resultado' => $resultado,
], 'Conexión con SUNAT');
