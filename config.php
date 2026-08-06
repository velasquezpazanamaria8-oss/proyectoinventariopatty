<?php
/**
 * Configuración del sistema.
 * En producción (Hostinger) copiar este archivo y ajustar credenciales.
 */
return [
    'app' => [
        'nombre'    => 'Sistema de Kardex e Inventarios',
        'base_url'  => '/proyectoinventariopatty',  // sin slash final
        'zona'      => 'America/Lima',
        'debug'     => true,                        // false en producción
        'almacen_default' => 1,
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'puerto'  => 3306,
        'nombre'  => 'kardex_inventario',
        'usuario' => 'root',
        'clave'   => '',
        'charset' => 'utf8mb4',
    ],
    'sesion' => [
        'nombre'          => 'KARDEXSESS',
        'vida_minutos'    => 120,
        'max_intentos'    => 5,
        'bloqueo_minutos' => 15,
    ],
];
