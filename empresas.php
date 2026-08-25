<?php
require_once __DIR__ . '/bootstrap.php';

// --- Cambio de empresa activa (disponible para cualquier usuario con varias) ---
if (($_GET['a'] ?? '') === 'cambiar') {
    Auth::requiereLogin();
    $destino = (int) ($_GET['id'] ?? 0);
    if (Empresa::activar($destino, Auth::id())) {
        Auditoria::registrar('CAMBIO_EMPRESA', 'empresas', $destino);
        Sesion::flash('ok', 'Trabajando ahora en: ' . Empresa::actual()['razon_social']);
    } else {
        Sesion::flash('error', 'No tiene acceso a esa empresa.');
    }
    Vista::redirigir('index.php');
}

// --- Administración de empresas (sólo rol global) ---
Auth::requierePermiso('empresas.gestionar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    $op = $_POST['op'] ?? 'guardar';
    try {
        // Desactivar, reactivar y eliminar viajan por POST y no por un enlace:
        // cambian el estado del sistema, y una simple <img> con esa dirección
        // bastaría para dispararlos desde fuera con la sesión del administrador.
        if ($op === 'desactivar') {
            Empresa::desactivar((int) $_POST['id']);
            Sesion::flash('ok', 'Empresa desactivada. Sus datos se conservan.');

        } elseif ($op === 'reactivar') {
            Empresa::activarDeNuevo((int) $_POST['id']);
            Sesion::flash('ok', 'Empresa reactivada.');

        } elseif ($op === 'eliminar') {
            $id  = (int) $_POST['id'];
            $emp = Empresa::buscar($id);
            Empresa::eliminar($id, (string) ($_POST['ruc_confirmacion'] ?? ''));
            Sesion::flash('ok', 'Se eliminó ' . ($emp['razon_social'] ?? 'la empresa')
                . ' y todos sus datos de forma definitiva.');

        } else {
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            Empresa::guardar($_POST, $id);
            Sesion::flash('ok', $id
                ? 'Empresa actualizada.'
                : 'Empresa creada con sus catálogos base (almacén, unidades, categoría y marca).');
        }
    } catch (PDOException $e) {
        Sesion::flash('error', $e->errorInfo[1] == 1062 ? 'Ya existe una empresa con ese RUC.' : $e->getMessage());
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('empresas.php');
}

$empresas = Empresa::listar(trim($_GET['q'] ?? ''));

// Qué se perdería al eliminar cada una: se enseña en la confirmación, para que
// nadie borre sin ver primero el tamaño de lo que se lleva por delante.
$contenido = [];
foreach ($empresas as $em) {
    $contenido[(int) $em['id']] = Empresa::contenido((int) $em['id']);
}

// Administrar las empresas no pertenece a ninguna de ellas: se muestra fuera
// del menú de la empresa activa para que no parezca filtrado por ella.
Vista::renderSistema('empresas/index', [
    'empresas'  => $empresas,
    'contenido' => $contenido,
    'editar'    => !empty($_GET['id']) ? Empresa::buscar((int) $_GET['id']) : null,
    'q'         => trim($_GET['q'] ?? ''),
], 'Empresas del sistema');
