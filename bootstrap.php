<?php
/**
 * Punto de entrada único: carga configuración, clases y sesión.
 * Todo archivo público debe hacer: require_once __DIR__ . '/bootstrap.php';
 */

define('BASE_PATH', __DIR__);

$config = require BASE_PATH . '/config.php';

date_default_timezone_set($config['app']['zona']);
mb_internal_encoding('UTF-8');

if ($config['app']['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
}

// Autoload simple: app/Clase.php  y  app/models/Clase.php
spl_autoload_register(function (string $clase): void {
    foreach (['/app/', '/app/models/', '/app/services/'] as $dir) {
        $ruta = BASE_PATH . $dir . $clase . '.php';
        if (is_file($ruta)) { require_once $ruta; return; }
    }
});

Config::cargar($config);
DB::init($config['db']);
Sesion::iniciar($config['sesion']);
