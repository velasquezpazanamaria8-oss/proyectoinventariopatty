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

    // 5. Credenciales SUNAT por empresa
    if (!existeTabla('credenciales_sunat')) {
        DB::query("CREATE TABLE credenciales_sunat (
          id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          empresa_id     INT UNSIGNED NOT NULL,
          ruc            VARCHAR(11)  NOT NULL,
          usuario_sol    VARCHAR(60)  NOT NULL,
          clave_sol      VARCHAR(255) NOT NULL,
          client_id      VARCHAR(120) NULL,
          client_secret  VARCHAR(255) NULL,
          estado         ENUM('SIN_PROBAR','OK','ERROR') NOT NULL DEFAULT 'SIN_PROBAR',
          mensaje        VARCHAR(400) NULL,
          recursos       TEXT         NULL,
          verificado_en  DATETIME     NULL,
          creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          actualizado_en DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
          CONSTRAINT fk_cred_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
          UNIQUE KEY uq_cred_empresa (empresa_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla credenciales_sunat');
        $cambios++;
    } else {
        paso('[--] credenciales_sunat ya existe');
    }

    // 6. Comprobantes del SIRE
    if (!existeTabla('sunat_comprobantes')) {
        DB::query("CREATE TABLE sunat_comprobantes (
          id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          empresa_id     INT UNSIGNED NOT NULL,
          periodo        CHAR(6)      NOT NULL,
          tipo           ENUM('ventas','compras') NOT NULL,
          cod_tipo_cdp   VARCHAR(4)   NULL,
          serie          VARCHAR(20)  NULL,
          numero         VARCHAR(30)  NULL,
          fecha_emision  DATE         NULL,
          ruc_contraparte    VARCHAR(15)  NULL,
          nombre_contraparte VARCHAR(255) NULL,
          base_gravada   DECIMAL(14,2) NOT NULL DEFAULT 0,
          igv            DECIMAL(14,2) NOT NULL DEFAULT 0,
          total          DECIMAL(14,2) NOT NULL DEFAULT 0,
          moneda         VARCHAR(5)   NULL,
          estado_sunat   VARCHAR(40)  NULL,
          payload        MEDIUMTEXT   NULL,
          sincronizado_en DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_sc_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
          UNIQUE KEY uq_sc (empresa_id, periodo, tipo, cod_tipo_cdp, serie, numero, ruc_contraparte),
          INDEX idx_sc_periodo (empresa_id, periodo, tipo),
          INDEX idx_sc_fecha (empresa_id, fecha_emision)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla sunat_comprobantes');
        $cambios++;
    } else {
        paso('[--] sunat_comprobantes ya existe');
    }

    // 6b. Corrección de la clave natural de sunat_comprobantes
    if (existeTabla('sunat_comprobantes')) {
        $clave = DB::todos("SHOW INDEX FROM sunat_comprobantes WHERE Key_name = 'uq_sc'");
        $cols  = array_column($clave, 'Column_name');
        if (!in_array('ruc_contraparte', $cols, true)) {
            // La clave anterior no distinguía factura de nota de crédito ni
            // dos proveedores con la misma serie: perdía comprobantes.
            //
            // Vaciar la tabla es seguro SÓLO si todavía no se generaron
            // movimientos: cada comprobante guarda en mov_id qué entrada o
            // salida produjo, y ese enlace es lo único que impide duplicar.
            // Si se borra con movimientos ya generados, al volver a
            // sincronizar todos vuelven con mov_id NULL y la fase 4 los
            // convierte por segunda vez: el stock se duplica en silencio.
            $yaGenerados = existeColumna('sunat_comprobantes', 'mov_id')
                ? (int) DB::valor('SELECT COUNT(*) FROM sunat_comprobantes WHERE mov_id IS NOT NULL')
                : 0;

            if ($yaGenerados > 0) {
                paso("[!!] sunat_comprobantes: la clave natural es la antigua, pero hay $yaGenerados");
                paso('     comprobante(s) que YA generaron movimientos de inventario.');
                paso('     No se toca nada: vaciar la tabla ahora perdería el enlace mov_id y');
                paso('     al re-sincronizar se volverían a generar esos movimientos (stock');
                paso('     duplicado). Corrija la clave a mano tras respaldar, o anule primero');
                paso('     los movimientos generados.');
            } else {
                DB::query('DELETE FROM sunat_comprobantes');   // se vuelve a sincronizar
                DB::query('ALTER TABLE sunat_comprobantes DROP INDEX uq_sc,
                            ADD UNIQUE KEY uq_sc (empresa_id, periodo, tipo, cod_tipo_cdp, serie, numero, ruc_contraparte)');
                paso('[OK] sunat_comprobantes: clave natural corregida (hay que volver a sincronizar los períodos)');
                $cambios++;
            }
        } else {
            paso('[--] sunat_comprobantes ya tiene la clave natural correcta');
        }
    }

    // 6c. Fase 2: columnas de descarga y tabla de ítems
    if (existeTabla('sunat_comprobantes') && !existeColumna('sunat_comprobantes', 'descarga_estado')) {
        DB::query("ALTER TABLE sunat_comprobantes
                     ADD descarga_estado ENUM('PENDIENTE','OK','ERROR') NULL,
                     ADD descarga_msg VARCHAR(250) NULL,
                     ADD items_cant SMALLINT UNSIGNED NULL,
                     ADD xml_path VARCHAR(255) NULL,
                     ADD pdf_path VARCHAR(255) NULL,
                     ADD cdr_path VARCHAR(255) NULL,
                     ADD descargado_en DATETIME NULL");
        paso('[OK] sunat_comprobantes: columnas de descarga agregadas');
        $cambios++;
    } else {
        paso('[--] sunat_comprobantes ya tiene las columnas de descarga');
    }

    if (!existeTabla('sunat_cpe_items')) {
        DB::query("CREATE TABLE sunat_cpe_items (
          id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          empresa_id     INT UNSIGNED NOT NULL,
          cpe_id         BIGINT UNSIGNED NOT NULL,
          linea          SMALLINT UNSIGNED NOT NULL,
          codigo_sunat   VARCHAR(60)  NULL,
          descripcion    VARCHAR(500) NOT NULL,
          cantidad       DECIMAL(14,4) NOT NULL,
          unidad_codigo  VARCHAR(10)  NULL,
          unidad_nombre  VARCHAR(60)  NULL,
          valor_unitario DECIMAL(14,6) NOT NULL DEFAULT 0,
          importe        DECIMAL(14,4) NOT NULL DEFAULT 0,
          producto_id    INT UNSIGNED NULL,
          CONSTRAINT fk_cpei_emp  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
          CONSTRAINT fk_cpei_cpe  FOREIGN KEY (cpe_id)     REFERENCES sunat_comprobantes(id) ON DELETE CASCADE,
          CONSTRAINT fk_cpei_prod FOREIGN KEY (producto_id) REFERENCES productos(id),
          INDEX idx_cpei_cpe (cpe_id),
          INDEX idx_cpei_codigo (empresa_id, codigo_sunat),
          INDEX idx_cpei_producto (producto_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla sunat_cpe_items');
        $cambios++;
    } else {
        paso('[--] sunat_cpe_items ya existe');
    }

    // 6d. Contador de intentos de descarga
    if (existeTabla('sunat_comprobantes') && !existeColumna('sunat_comprobantes', 'descarga_intentos')) {
        DB::query('ALTER TABLE sunat_comprobantes ADD descarga_intentos TINYINT UNSIGNED NOT NULL DEFAULT 0');
        paso('[OK] sunat_comprobantes: contador de intentos agregado');
        $cambios++;
    } else {
        paso('[--] sunat_comprobantes ya tiene descarga_intentos');
    }

    // 6e. Fase 3: mapa de equivalencias y stock inicial
    if (!existeTabla('sunat_producto_mapa')) {
        DB::query("CREATE TABLE sunat_producto_mapa (
          id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          empresa_id  INT UNSIGNED NOT NULL,
          origen_ruc  VARCHAR(15)  NOT NULL,
          clave       VARCHAR(255) NOT NULL,
          producto_id INT UNSIGNED NULL,
          ignorar     TINYINT(1)   NOT NULL DEFAULT 0,
          descripcion VARCHAR(500) NULL,
          creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_map_emp  FOREIGN KEY (empresa_id)  REFERENCES empresas(id) ON DELETE CASCADE,
          CONSTRAINT fk_map_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
          UNIQUE KEY uq_map (empresa_id, origen_ruc, clave)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla sunat_producto_mapa');
        $cambios++;
    } else { paso('[--] sunat_producto_mapa ya existe'); }

    if (!existeTabla('sunat_stock_inicial')) {
        DB::query("CREATE TABLE sunat_stock_inicial (
          id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          empresa_id  INT UNSIGNED NOT NULL,
          producto_id INT UNSIGNED NOT NULL,
          almacen_id  INT UNSIGNED NOT NULL,
          cantidad    DECIMAL(14,4) NOT NULL DEFAULT 0,
          costo_unitario DECIMAL(14,4) NOT NULL DEFAULT 0,
          aplicado_en DATETIME NULL,
          CONSTRAINT fk_si_emp  FOREIGN KEY (empresa_id)  REFERENCES empresas(id) ON DELETE CASCADE,
          CONSTRAINT fk_si_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
          CONSTRAINT fk_si_alm  FOREIGN KEY (almacen_id)  REFERENCES almacenes(id),
          UNIQUE KEY uq_si (empresa_id, producto_id, almacen_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla sunat_stock_inicial');
        $cambios++;
    } else { paso('[--] sunat_stock_inicial ya existe'); }

    // 6f. Fase 4: enlace con el movimiento generado
    if (existeTabla('sunat_comprobantes') && !existeColumna('sunat_comprobantes', 'mov_tabla')) {
        DB::query("ALTER TABLE sunat_comprobantes
                     ADD mov_tabla ENUM('entradas','salidas') NULL,
                     ADD mov_id INT UNSIGNED NULL,
                     ADD mov_msg VARCHAR(300) NULL,
                     ADD generado_en DATETIME NULL");
        paso('[OK] sunat_comprobantes: enlace con el movimiento generado');
        $cambios++;
    } else {
        paso('[--] sunat_comprobantes ya tiene el enlace con el movimiento');
    }

    // 7. Clientes
    //
    // Hasta ahora sólo había proveedores: el sistema registraba lo que entra,
    // no a quién se le vende. Las cotizaciones necesitan el otro lado. Misma
    // forma que proveedores, para que el catálogo genérico lo maneje igual.
    if (!existeTabla('clientes')) {
        DB::query("CREATE TABLE clientes (
          id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          empresa_id   INT UNSIGNED NOT NULL,
          ruc          VARCHAR(20)  NULL,
          razon_social VARCHAR(180) NOT NULL,
          telefono     VARCHAR(30)  NULL,
          email        VARCHAR(150) NULL,
          direccion    VARCHAR(255) NULL,
          estado       TINYINT(1)   NOT NULL DEFAULT 1,
          creado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_cli_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id),
          -- El mismo RUC puede ser cliente de varias empresas del grupo, pero
          -- no dos veces dentro de la misma.
          UNIQUE KEY uq_cli_emp_ruc (empresa_id, ruc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla clientes');
        $cambios++;
    } else { paso('[--] clientes ya existe'); }

    // 8. Cotizaciones
    //
    // El número es correlativo POR EMPRESA, no global: cada una lleva su propia
    // serie, como la llevaba en su Excel. La clave única sobre (empresa, número)
    // es la que de verdad impide dos cotizaciones con el mismo número si dos
    // personas guardan a la vez.
    if (!existeTabla('cotizaciones')) {
        DB::query("CREATE TABLE cotizaciones (
          id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          empresa_id    INT UNSIGNED NOT NULL,
          numero        INT UNSIGNED NOT NULL,
          cliente_id    INT UNSIGNED NULL,
          -- El nombre del cliente se copia al emitir: si mañana se corrige la
          -- ficha, la cotización ya enviada debe seguir diciendo lo que decía.
          cliente_nombre    VARCHAR(180) NOT NULL,
          cliente_ruc       VARCHAR(20)  NULL,
          cliente_direccion VARCHAR(255) NULL,
          cliente_email     VARCHAR(150) NULL,
          fecha         DATE NOT NULL,
          valida_hasta  DATE NULL,
          referencia    VARCHAR(120) NULL,
          estado        ENUM('BORRADOR','ENVIADA','ACEPTADA','RECHAZADA','ANULADA')
                        NOT NULL DEFAULT 'BORRADOR',
          -- Cómo se capturaron los precios. Todas las plantillas actuales
          -- cotizan con IGV incluido, pero conviene no darlo por sentado.
          incluye_igv   TINYINT(1) NOT NULL DEFAULT 1,
          subtotal      DECIMAL(14,2) NOT NULL DEFAULT 0,
          igv           DECIMAL(14,2) NOT NULL DEFAULT 0,
          total         DECIMAL(14,2) NOT NULL DEFAULT 0,
          observacion   TEXT NULL,
          -- Enlace con la salida generada cuando el cliente acepta (fase 6).
          salida_id     INT UNSIGNED NULL,
          usuario_id    INT UNSIGNED NULL,
          creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_cot_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id),
          CONSTRAINT fk_cot_cli FOREIGN KEY (cliente_id) REFERENCES clientes(id),
          UNIQUE KEY uq_cot_emp_num (empresa_id, numero),
          INDEX idx_cot_emp_fecha (empresa_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla cotizaciones');
        $cambios++;
    } else { paso('[--] cotizaciones ya existe'); }

    if (!existeTabla('cotizacion_detalle')) {
        // producto_id es OPCIONAL a propósito: siete de las ocho empresas no
        // llevan catálogo y cotizan escribiendo la descripción, como hacían en
        // Excel. Cuando sí viene del catálogo, la línea puede convertirse
        // después en un movimiento de inventario.
        DB::query("CREATE TABLE cotizacion_detalle (
          id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          cotizacion_id  INT UNSIGNED NOT NULL,
          linea          SMALLINT UNSIGNED NOT NULL,
          producto_id    INT UNSIGNED NULL,
          descripcion    VARCHAR(400) NOT NULL,
          unidad         VARCHAR(20)  NULL,
          cantidad       DECIMAL(14,4) NOT NULL,
          precio_unitario DECIMAL(14,4) NOT NULL,
          importe        DECIMAL(14,2) NOT NULL,
          CONSTRAINT fk_cotd_cot FOREIGN KEY (cotizacion_id)
                     REFERENCES cotizaciones(id) ON DELETE CASCADE,
          CONSTRAINT fk_cotd_pro FOREIGN KEY (producto_id) REFERENCES productos(id),
          INDEX idx_cotd_cot (cotizacion_id, linea)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla cotizacion_detalle');
        $cambios++;
    } else { paso('[--] cotizacion_detalle ya existe'); }

    // 9. Diseño de la cotización, por empresa
    //
    // Las ocho empresas del grupo maquetaron su cotización por separado y cada
    // una salió distinta: el logo a un lado o a otro, las columnas en otro
    // orden y con otros nombres, condiciones y cuentas propias. Eso es
    // deliberado —no quieren parecer la misma casa— así que en vez de ocho
    // plantillas se guarda lo que cambia y el generador es uno solo.
    if (!existeTabla('cotizacion_config')) {
        DB::query("CREATE TABLE cotizacion_config (
          empresa_id     INT UNSIGNED NOT NULL PRIMARY KEY,
          logo_ruta      VARCHAR(255) NULL,
          logo_posicion  ENUM('IZQUIERDA','CENTRO','DERECHA') NOT NULL DEFAULT 'IZQUIERDA',
          color          CHAR(7)      NOT NULL DEFAULT '#12395B',
          titulo         VARCHAR(60)  NOT NULL DEFAULT 'COTIZACIÓN',
          prefijo        VARCHAR(20)  NULL,
          digitos        TINYINT UNSIGNED NOT NULL DEFAULT 4,
          etiqueta_ref   VARCHAR(60)  NOT NULL DEFAULT 'SEGÚN REQUERIMIENTO',
          -- Unas ponen 'EMPRESA:', 'RUC:' delante del dato y otras el dato solo.
          emisor_etiquetas TINYINT(1) NOT NULL DEFAULT 0,
          emisor_derecha   TINYINT(1) NOT NULL DEFAULT 0,
          mostrar_telefono TINYINT(1) NOT NULL DEFAULT 0,
          mostrar_fecha    TINYINT(1) NOT NULL DEFAULT 1,
          -- Qué columnas, en qué orden y con qué nombre. Guardado como JSON
          -- porque es una lista variable y no se consulta por sus valores.
          columnas       TEXT NULL,
          condiciones    TEXT NULL,
          notas          TEXT NULL,
          firma_izq      VARCHAR(80) NULL,
          firma_der      VARCHAR(80) NULL,
          incluye_igv    TINYINT(1) NOT NULL DEFAULT 1,
          actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          CONSTRAINT fk_cotcfg_emp FOREIGN KEY (empresa_id)
                     REFERENCES empresas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla cotizacion_config');
        $cambios++;
    } else { paso('[--] cotizacion_config ya existe'); }

    // Permiso de cotizaciones
    if (!(int) DB::valor("SELECT COUNT(*) FROM permisos WHERE clave = 'cotizaciones.gestionar'")) {
        $pid = DB::insertar('permisos', ['clave' => 'cotizaciones.gestionar',
            'descripcion' => 'Emitir cotizaciones y definir su diseño']);
        DB::query('INSERT INTO rol_permiso (rol_id, permiso_id)
                   SELECT id, :p FROM roles WHERE nombre IN (\'SUPERADMIN\',\'ADMINISTRADOR\')', [':p' => $pid]);
        paso('[OK] permiso cotizaciones.gestionar creado');
        $cambios++;
    } else { paso('[--] el permiso cotizaciones.gestionar ya existe'); }

    // 6f-bis. Descargas que quedaron en marcha
    //
    // La descarga avanza por lotes y puede durar mucho. Si el usuario recarga,
    // cierra la pestaña o se le va la conexión, esto recuerda que ese período
    // seguía bajándose, y la pantalla lo retoma sola al volver a abrirla.
    if (!existeTabla('sunat_descargas_activas')) {
        DB::query("CREATE TABLE sunat_descargas_activas (
          empresa_id  INT UNSIGNED NOT NULL,
          periodo     CHAR(6)      NOT NULL,
          iniciada_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          ultimo_lote DATETIME     NULL,
          PRIMARY KEY (empresa_id, periodo),
          CONSTRAINT fk_desact_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla sunat_descargas_activas');
        $cambios++;
    } else { paso('[--] sunat_descargas_activas ya existe'); }

    // 6g. Fase 5: registro de ejecuciones automáticas
    if (!existeTabla('sunat_tareas')) {
        DB::query("CREATE TABLE sunat_tareas (
          id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          empresa_id INT UNSIGNED NOT NULL,
          origen     ENUM('cron','manual') NOT NULL DEFAULT 'cron',
          estado     ENUM('CORRIENDO','OK','ERROR') NOT NULL DEFAULT 'CORRIENDO',
          periodo    CHAR(6)      NULL,
          resumen    VARCHAR(500) NULL,
          detalle    TEXT         NULL,
          iniciado_en   DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
          terminado_en  DATETIME  NULL,
          CONSTRAINT fk_tar_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
          INDEX idx_tar_emp (empresa_id, iniciado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        paso('[OK] creada la tabla sunat_tareas');
        $cambios++;
    } else { paso('[--] sunat_tareas ya existe'); }

    // 6h. Claves de conciliación no informativas
    if (existeTabla('sunat_producto_mapa')) {
        // Un código como "-" o "SN" no identifica nada: agrupaba productos
        // distintos del mismo proveedor bajo una sola equivalencia. Esas filas
        // se borran para que se vuelvan a decidir una por una.
        $malas = DB::todos(
            "SELECT id, clave FROM sunat_producto_mapa
              WHERE TRIM(clave) NOT REGEXP '[A-Za-z0-9]'
                 OR UPPER(TRIM(clave)) IN ('SN','NA','N/A','S/N','0')");
        if ($malas) {
            $ids = implode(',', array_map('intval', array_column($malas, 'id')));
            DB::query("DELETE FROM sunat_producto_mapa WHERE id IN ($ids)");
            // Las líneas que apuntaban por esa vía vuelven a quedar sin decidir.
            DB::query("UPDATE sunat_cpe_items SET producto_id = NULL
                        WHERE TRIM(COALESCE(codigo_sunat,'')) NOT REGEXP '[A-Za-z0-9]'
                           OR UPPER(TRIM(codigo_sunat)) IN ('SN','NA','N/A','S/N','0')");
            paso('[OK] eliminadas ' . count($malas) . ' equivalencia(s) con código no informativo');
            paso('     (esos ítems vuelven a la conciliación, ahora agrupados por descripción)');
            $cambios++;
        } else {
            paso('[--] no hay equivalencias con códigos no informativos');
        }
    }

    // 7. Permiso para la pantalla de conexión SUNAT
    $existePermiso = (int) DB::valor("SELECT COUNT(*) FROM permisos WHERE clave = 'sunat.gestionar'");
    if (!$existePermiso) {
        $pid = DB::insertar('permisos', ['clave' => 'sunat.gestionar', 'descripcion' => 'Configurar la conexión con SUNAT']);
        // Se otorga a los roles que administran: superadmin y administrador.
        DB::query('INSERT INTO rol_permiso (rol_id, permiso_id)
                   SELECT id, :p FROM roles WHERE nombre IN (\'SUPERADMIN\',\'ADMINISTRADOR\')', [':p' => $pid]);
        paso('[OK] permiso sunat.gestionar creado y asignado a SUPERADMIN y ADMINISTRADOR');
        $cambios++;
    } else {
        paso('[--] el permiso sunat.gestionar ya existe');
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
