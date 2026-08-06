<?php
/**
 * Actualiza una base ya instalada al esquema con métodos de valorización,
 * sin perder los datos existentes.
 *
 *   Navegador: http://localhost/proyectoinventariopatty/migrar.php
 *   Consola:   php migrar.php
 *
 * Es idempotente: puede ejecutarse varias veces sin efecto adicional.
 * Eliminar este archivo una vez aplicado en producción.
 */
require_once __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}
function paso(string $t = ''): void { echo $t . PHP_EOL; }

$bd = Config::get('db.nombre');

function existeColumna(string $tabla, string $columna): bool
{
    return (int) DB::valor(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
        [':t' => $tabla, ':c' => $columna]) > 0;
}

function existeTabla(string $tabla): bool
{
    return (int) DB::valor(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = :t', [':t' => $tabla]) > 0;
}

try {
    paso("Migrando la base '$bd'...");
    paso('');
    $cambios = 0;

    // 1. Método y ámbito en la empresa
    if (!existeColumna('empresas', 'metodo_valorizacion')) {
        DB::query("ALTER TABLE empresas
                     ADD metodo_valorizacion ENUM('PROMEDIO','PEPS','UEPS') NOT NULL DEFAULT 'PROMEDIO' AFTER simbolo,
                     ADD ambito_costo ENUM('GLOBAL','ALMACEN') NOT NULL DEFAULT 'GLOBAL' AFTER metodo_valorizacion");
        paso('[OK] empresas: agregadas metodo_valorizacion y ambito_costo (por defecto PROMEDIO / GLOBAL)');
        $cambios++;
    } else {
        paso('[--] empresas ya tiene las columnas de valorización');
    }

    // 2. Costo por almacén
    if (!existeColumna('stock', 'costo_promedio')) {
        DB::query('ALTER TABLE stock ADD costo_promedio DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER reservado');
        // Se arrastra el costo que hoy tiene el producto: es el único dato
        // histórico disponible y deja ambos ámbitos coherentes de partida.
        $n = DB::query('UPDATE stock s
                          JOIN productos p ON p.id = s.producto_id
                           SET s.costo_promedio = p.costo_promedio')->rowCount();
        paso("[OK] stock: agregada costo_promedio y copiada desde productos ($n fila(s))");
        $cambios++;
    } else {
        paso('[--] stock ya tiene costo_promedio');
    }

    // 3. Capas de costo para PEPS/UEPS
    if (!existeTabla('capas_costo')) {
        DB::query("CREATE TABLE capas_costo (
          id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          empresa_id     INT UNSIGNED NOT NULL,
          producto_id    INT UNSIGNED NOT NULL,
          almacen_id     INT UNSIGNED NOT NULL,
          kardex_id      BIGINT UNSIGNED NULL,
          fecha          DATETIME     NOT NULL,
          cantidad_ini   DECIMAL(14,4) NOT NULL,
          cantidad_resta DECIMAL(14,4) NOT NULL,
          costo_unitario DECIMAL(14,4) NOT NULL,
          documento      VARCHAR(60)  NULL,
          creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_capa_emp  FOREIGN KEY (empresa_id)  REFERENCES empresas(id),
          CONSTRAINT fk_capa_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
          CONSTRAINT fk_capa_alm  FOREIGN KEY (almacen_id)  REFERENCES almacenes(id),
          INDEX idx_capa_cola (producto_id, almacen_id, fecha, id),
          INDEX idx_capa_resta (producto_id, almacen_id, cantidad_resta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla capas_costo');
        $cambios++;
    } else {
        paso('[--] capas_costo ya existe');
    }

    if (!existeTabla('kardex_capa')) {
        DB::query("CREATE TABLE kardex_capa (
          id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          kardex_id      BIGINT UNSIGNED NOT NULL,
          capa_id        BIGINT UNSIGNED NOT NULL,
          cantidad       DECIMAL(14,4) NOT NULL,
          costo_unitario DECIMAL(14,4) NOT NULL,
          CONSTRAINT fk_kc_kardex FOREIGN KEY (kardex_id) REFERENCES kardex(id) ON DELETE CASCADE,
          CONSTRAINT fk_kc_capa   FOREIGN KEY (capa_id)   REFERENCES capas_costo(id),
          INDEX idx_kc_kardex (kardex_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla kardex_capa');
        $cambios++;
    } else {
        paso('[--] kardex_capa ya existe');
    }

    paso('');
    paso($cambios ? "Migración completada ($cambios cambio(s))." : 'La base ya estaba actualizada.');
    paso('');
    paso('Las empresas existentes quedan en PROMEDIO con ámbito GLOBAL, que');
    paso('es el criterio que corrige el desvío del costo entre almacenes.');
    paso('El método sólo puede cambiarse en empresas sin movimientos.');

} catch (Throwable $e) {
    http_response_code(500);
    paso('[ERROR] ' . $e->getMessage());
}
