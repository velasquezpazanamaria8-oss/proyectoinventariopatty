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

// Por defecto se ve quién tiene acceso a ESTA empresa. El superadmin puede
// pedir la lista completa del sistema, que es otra pregunta distinta.
$todos = Auth::esSuperAdmin() && !empty($_GET['todos']);

Vista::render('usuarios/index', [
    'usuarios' => Usuario::listar(trim($_GET['q'] ?? ''), $todos),
    'editar'   => !empty($_GET['id']) ? Usuario::buscar((int) $_GET['id']) : null,
    'roles'    => Usuario::roles(),
    'q'        => trim($_GET['q'] ?? ''),
    'todos'    => $todos,
    'puedeVerTodos' => Auth::esSuperAdmin(),
], 'Usuarios');
