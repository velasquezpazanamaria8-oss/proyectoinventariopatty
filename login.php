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
            // Con varias empresas se pasa por la portada: elegir a la vista evita
            // ponerse a trabajar en la que quedó activa de la sesión anterior.
            Vista::redirigir(count(Empresa::delUsuario(Auth::id())) > 1
                ? 'elegir_empresa.php' : 'index.php');
        }
        $error = $msg;
    }
}

Vista::renderPlano('auth/login', ['error' => $error]);
