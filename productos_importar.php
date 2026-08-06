<?php
/**
 * Importación masiva de productos desde Excel/CSV. RF-03.
 *
 * Flujo: subir archivo -> vista previa validada -> confirmar.
 * El archivo se guarda en storage/importaciones (fuera del alcance web por
 * .htaccess) y se identifica con un token guardado en sesión, para que la
 * confirmación no dependa de una ruta enviada por el navegador.
 */
require_once __DIR__ . '/bootstrap.php';
Auth::requierePermiso('productos.gestionar');

const DIR_IMPORT = BASE_PATH . '/storage/importaciones';

if (($_GET['a'] ?? '') === 'plantilla') {
    ImportadorProductos::plantilla();
}

$previo   = null;
$opciones = [
    'crear_faltantes'       => true,
    'actualizar_existentes' => false,
    'almacen_id'            => (int) Config::get('app.almacen_default', 1),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verificar();
    $op = [
        'crear_faltantes'       => !empty($_POST['crear_faltantes']),
        'actualizar_existentes' => !empty($_POST['actualizar_existentes']),
        'almacen_id'            => (int) ($_POST['almacen_id'] ?? 0),
    ];
    $opciones = $op;

    try {
        // --- Paso 1: subida y análisis ---
        if (($_POST['op'] ?? '') === 'analizar') {
            $f = $_FILES['archivo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException(match ($f['error'] ?? -1) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño permitido por el servidor.',
                    UPLOAD_ERR_NO_FILE => 'Seleccione un archivo.',
                    default => 'No se pudo recibir el archivo.',
                });
            }
            if ($f['size'] > 8 * 1024 * 1024) {
                throw new RuntimeException('El archivo supera los 8 MB.');
            }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'xlsm', 'csv', 'txt', 'xls'], true)) {
                throw new RuntimeException('Formato no permitido. Use .xlsx o .csv.');
            }

            if (!is_dir(DIR_IMPORT)) {
                mkdir(DIR_IMPORT, 0775, true);
            }
            $token   = bin2hex(random_bytes(16));
            $destino = DIR_IMPORT . '/' . $token . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
            if (!move_uploaded_file($f['tmp_name'], $destino)) {
                throw new RuntimeException('No se pudo guardar el archivo subido.');
            }

            // Se limpian cargas anteriores del usuario para no acumular basura.
            self_limpiarAntiguos();

            $previo = ImportadorProductos::analizar($destino, $f['name'], $op);
            Sesion::set('import_productos', [
                'ruta'    => $destino,
                'nombre'  => $f['name'],
                'empresa' => Empresa::id(),
            ]);
        }

        // --- Paso 2: aplicar ---
        if (($_POST['op'] ?? '') === 'aplicar') {
            $ctx = Sesion::get('import_productos');
            if (!$ctx || !is_file($ctx['ruta'])) {
                throw new RuntimeException('La carga expiró. Vuelva a subir el archivo.');
            }
            if ((int) $ctx['empresa'] !== Empresa::id()) {
                throw new RuntimeException('El archivo se subió en otra empresa. Vuelva a subirlo.');
            }

            $r = ImportadorProductos::aplicar($ctx['ruta'], $ctx['nombre'], $op);

            @unlink($ctx['ruta']);
            Sesion::quitar('import_productos');

            $msg = "Importación completada: {$r['creados']} producto(s) creado(s)";
            if ($r['actualizados']) $msg .= ", {$r['actualizados']} actualizado(s)";
            if ($r['con_stock'])    $msg .= ", {$r['con_stock']} con carga inicial de stock en el kardex";
            Sesion::flash('ok', $msg . '.');
            Vista::redirigir('productos.php');
        }
    } catch (Throwable $e) {
        Sesion::flash('error', $e->getMessage());
    }
}

/** Borra archivos de importación de más de 2 horas. */
function self_limpiarAntiguos(): void
{
    foreach (glob(DIR_IMPORT . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 7200) {
            @unlink($f);
        }
    }
}

Vista::render('productos/importar', [
    'previo'    => $previo,
    'opciones'  => $opciones,
    'almacenes' => Catalogo::opciones('almacenes'),
], 'Importar productos desde Excel');
