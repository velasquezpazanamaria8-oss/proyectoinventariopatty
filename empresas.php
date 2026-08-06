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
    try {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $nuevaId = Empresa::guardar($_POST, $id);
        Sesion::flash('ok', $id
            ? 'Empresa actualizada.'
            : 'Empresa creada con sus catálogos base (almacén, unidades, categoría y marca).');
    } catch (PDOException $e) {
        Sesion::flash('error', $e->errorInfo[1] == 1062 ? 'Ya existe una empresa con ese RUC.' : $e->getMessage());
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('empresas.php');
}

if (($_GET['a'] ?? '') === 'desactivar') {
    try {
        Empresa::desactivar((int) $_GET['id']);
        Sesion::flash('ok', 'Empresa desactivada.');
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('empresas.php');
}

Vista::render('empresas/index', [
    'empresas' => Empresa::listar(trim($_GET['q'] ?? '')),
    'editar'   => !empty($_GET['id']) ? Empresa::buscar((int) $_GET['id']) : null,
    'q'        => trim($_GET['q'] ?? ''),
], 'Empresas del sistema');
