<?php
/**
 * PLANTILLA PARA EL HOSTING.
 *
 * Copie este archivo sobre `config.php` en el servidor y complete los datos
 * que le dio Hostinger. No suba este archivo de ejemplo al servidor.
 */
return [
    'app' => [
        'nombre'    => 'Sistema de Kardex e Inventarios',

        // Vacío si el sistema está en la raíz del dominio.
        // Si está en una subcarpeta, poner '/kardex' (sin barra final).
        'base_url'  => '',

        'zona'      => 'America/Lima',

        // SIEMPRE false en el servidor: con true, cualquier error muestra
        // rutas internas y fragmentos de consulta a quien visite la página.
        'debug'     => false,

        'almacen_default' => 1,

        // Clave de las tareas programadas. CAMBIARLA: mínimo 16 caracteres.
        // Generar una con: php -r "echo bin2hex(random_bytes(16));"
        'cron_clave' => 'CAMBIAR-POR-UNA-CLAVE-LARGA-Y-UNICA',
    ],
    'db' => [
        'host'    => 'localhost',
        'puerto'  => 3306,
        'nombre'  => 'u000000_kardex',      // nombre COMPLETO, con el prefijo
        'usuario' => 'u000000_admin',
        'clave'   => 'CAMBIAR-POR-LA-CLAVE-DE-LA-BASE',
        'charset' => 'utf8mb4',
    ],
    'sesion' => [
        'nombre'          => 'KARDEXSESS',
        'vida_minutos'    => 120,
        'max_intentos'    => 5,
        'bloqueo_minutos' => 15,
    ],
];
