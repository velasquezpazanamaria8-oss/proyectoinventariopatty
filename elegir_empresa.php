<?php
/**
 * Portada de selección de empresa.
 *
 * Con nueve empresas, el desplegable de la barra deja demasiado fácil trabajar
 * en la equivocada sin notarlo. Esta pantalla obliga a elegir a la vista, con el
 * logo y el RUC delante, y es la primera que se ve al entrar.
 *
 * No sustituye al desplegable: quien ya sabe a dónde va sigue cambiando desde
 * arriba sin pasar por aquí.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requiereLogin();

$empresas = Empresa::delUsuario(Auth::id());

// Con una sola empresa esta pantalla no aporta nada: se entra directo.
if (count($empresas) === 1) {
    Empresa::activar((int) $empresas[0]['id'], Auth::id());
    Vista::redirigir('index.php');
}

// Cuántas cotizaciones y productos tiene cada una, para dar contexto en la
// tarjeta sin tener que entrar a mirar.
$resumen = [];
foreach ($empresas as $e) {
    $id = (int) $e['id'];
    $resumen[$id] = [
        'logo'      => (bool) DB::valor('SELECT logo_ruta FROM cotizacion_config WHERE empresa_id = :e', [':e' => $id]),
        'productos' => (int) DB::valor('SELECT COUNT(*) FROM productos WHERE empresa_id = :e', [':e' => $id]),
        'cotiza'    => (int) DB::valor('SELECT COUNT(*) FROM cotizaciones WHERE empresa_id = :e', [':e' => $id]),
    ];
}

// Sin el menú lateral a propósito: mostrar la navegación de la empresa
// anterior mientras se pide elegir otra confunde más que ayuda.
Vista::renderPlano('empresas/elegir', [
    'empresas' => $empresas,
    'resumen'  => $resumen,
    'actual'   => Empresa::hayActiva() ? Empresa::id() : 0,
]);
