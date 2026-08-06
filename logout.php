<?php
require_once __DIR__ . '/bootstrap.php';
Auth::logout();
Sesion::iniciar(Config::get('sesion'));
Sesion::flash('ok', 'Sesión cerrada correctamente.');
Vista::redirigir('login.php');
