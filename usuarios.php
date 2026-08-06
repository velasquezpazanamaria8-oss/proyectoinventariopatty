<?php
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('usuarios.ver');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requierePermiso('usuarios.gestionar');
    Csrf::verificar();
    try {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        Usuario::guardar($_POST, $id);
        Sesion::flash('ok', $id ? 'Usuario actualizado.' : 'Usuario creado.');
    } catch (PDOException $e) {
        Sesion::flash('error', $e->errorInfo[1] == 1062 ? 'El usuario o email ya existe.' : $e->getMessage());
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('usuarios.php');
}

if (($_GET['a'] ?? '') === 'eliminar') {
    Auth::requierePermiso('usuarios.gestionar');
    try {
        Sesion::flash('ok', Usuario::eliminar((int) $_GET['id']));
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
    Vista::redirigir('usuarios.php');
}

Vista::render('usuarios/index', [
    'usuarios' => Usuario::listar(trim($_GET['q'] ?? '')),
    'editar'   => !empty($_GET['id']) ? Usuario::buscar((int) $_GET['id']) : null,
    'roles'    => Usuario::roles(),
    'q'        => trim($_GET['q'] ?? ''),
], 'Usuarios');
