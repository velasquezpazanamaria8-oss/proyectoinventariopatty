<?php
require_once __DIR__ . '/bootstrap.php';

if (Auth::autenticado()) {
    Vista::redirigir('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::valido($_POST['_csrf'] ?? null)) {
        $error = 'La sesión del formulario expiró. Intente nuevamente.';
    } else {
        [$ok, $msg] = Auth::intentarLogin(trim($_POST['usuario'] ?? ''), $_POST['clave'] ?? '');
        if ($ok) {
            Sesion::flash('ok', $msg);
            Vista::redirigir('index.php');
        }
        $error = $msg;
    }
}

Vista::renderPlano('auth/login', ['error' => $error]);
