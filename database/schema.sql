-- ============================================================
-- Sistema de Control de Kardex e Inventarios — MULTIEMPRESA
-- Motor: MySQL 8 / MariaDB 10.4+  (Laragon local, Hostinger prod)
--
-- Modelo de aislamiento: una sola base de datos con `empresa_id`
-- en cada tabla de negocio. TODA consulta debe filtrar por la
-- empresa activa (ver app/Empresa.php).
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Empresas (tenants)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS empresas;
CREATE TABLE empresas (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ruc           VARCHAR(20)  NOT NULL UNIQUE,
  razon_social  VARCHAR(180) NOT NULL,
  nombre_corto  VARCHAR(60)  NOT NULL,
  direccion     VARCHAR(255) NULL,
  telefono      VARCHAR(30)  NULL,
  email         VARCHAR(150) NULL,
  moneda        VARCHAR(10)  NOT NULL DEFAULT 'PEN',
  simbolo       VARCHAR(5)   NOT NULL DEFAULT 'S/',
  -- Método de valorización de las salidas. Sólo puede cambiarse mientras la
  -- empresa no tenga movimientos: cambiarlo a mitad de camino haría que el
  -- kardex mezclara criterios y dejaría de ser comparable.
  metodo_valorizacion ENUM('PROMEDIO','PEPS','UEPS') NOT NULL DEFAULT 'PROMEDIO',
  -- Ámbito del costo promedio: GLOBAL = un costo por producto sumando todos
  -- los almacenes; ALMACEN = un costo distinto en cada almacén.
  -- No aplica a PEPS/UEPS, que siempre trabajan por almacén (las capas están
  -- físicamente en un almacén).
  ambito_costo  ENUM('GLOBAL','ALMACEN') NOT NULL DEFAULT 'GLOBAL',
  estado        TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Seguridad: roles, usuarios, permisos  (RF-01, RF-02)
-- Los roles y permisos son globales; el vínculo usuario-empresa
-- se define en `usuario_empresa`.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(50)  NOT NULL UNIQUE,
  descripcion   VARCHAR(255) NULL,
  global        TINYINT(1)   NOT NULL DEFAULT 0,   -- 1 = ve todas las empresas
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS permisos;
CREATE TABLE permisos (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave         VARCHAR(80)  NOT NULL UNIQUE,
  descripcion   VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS rol_permiso;
CREATE TABLE rol_permiso (
  rol_id        INT UNSIGNED NOT NULL,
  permiso_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (rol_id, permiso_id),
  CONSTRAINT fk_rp_rol     FOREIGN KEY (rol_id)     REFERENCES roles(id)    ON DELETE CASCADE,
  CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El nombre de usuario es único a nivel de sistema: identifica el login.
DROP TABLE IF EXISTS usuarios;
CREATE TABLE usuarios (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario        VARCHAR(60)  NOT NULL UNIQUE,
  nombres        VARCHAR(120) NOT NULL,
  email          VARCHAR(150) NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  estado         TINYINT(1)   NOT NULL DEFAULT 1,
  ultimo_acceso  DATETIME     NULL,
  intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta   DATETIME  NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un usuario puede operar en varias empresas, con un rol distinto en cada una.
DROP TABLE IF EXISTS usuario_empresa;
CREATE TABLE usuario_empresa (
  usuario_id  INT UNSIGNED NOT NULL,
  empresa_id  INT UNSIGNED NOT NULL,
  rol_id      INT UNSIGNED NOT NULL,
  por_defecto TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (usuario_id, empresa_id),
  CONSTRAINT fk_ue_usr FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  CONSTRAINT fk_ue_rol FOREIGN KEY (rol_id)     REFERENCES roles(id),
  INDEX idx_ue_empresa (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Catálogos  (RF-03, RF-04) — todos por empresa
-- ------------------------------------------------------------
DROP TABLE IF EXISTS categorias;
CREATE TABLE categorias (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id  INT UNSIGNED NOT NULL,
  nombre      VARCHAR(120) NOT NULL,
  descripcion VARCHAR(255) NULL,
  estado      TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cat_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  UNIQUE KEY uq_cat_emp_nombre (empresa_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS marcas;
CREATE TABLE marcas (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  nombre     VARCHAR(120) NOT NULL,
  estado     TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mar_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  UNIQUE KEY uq_mar_emp_nombre (empresa_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS unidades;
CREATE TABLE unidades (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  codigo     VARCHAR(10)  NOT NULL,
  nombre     VARCHAR(60)  NOT NULL,
  decimales  TINYINT UNSIGNED NOT NULL DEFAULT 2,
  CONSTRAINT fk_uni_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  UNIQUE KEY uq_uni_emp_codigo (empresa_id, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS proveedores;
CREATE TABLE proveedores (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id   INT UNSIGNED NOT NULL,
  ruc          VARCHAR(20)  NULL,
  razon_social VARCHAR(180) NOT NULL,
  telefono     VARCHAR(30)  NULL,
  email        VARCHAR(150) NULL,
  direccion    VARCHAR(255) NULL,
  estado       TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prov_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  UNIQUE KEY uq_prov_emp_ruc (empresa_id, ruc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS almacenes;
CREATE TABLE almacenes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  codigo     VARCHAR(20)  NOT NULL,
  nombre     VARCHAR(120) NOT NULL,
  direccion  VARCHAR(255) NULL,
  estado     TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_alm_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  UNIQUE KEY uq_alm_emp_codigo (empresa_id, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Productos  (RF-03)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS productos;
CREATE TABLE productos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id     INT UNSIGNED NOT NULL,
  codigo         VARCHAR(40)  NOT NULL,
  descripcion    VARCHAR(255) NOT NULL,
  categoria_id   INT UNSIGNED NULL,
  marca_id       INT UNSIGNED NULL,
  unidad_id      INT UNSIGNED NOT NULL,
  precio_compra  DECIMAL(14,4) NOT NULL DEFAULT 0,
  precio_venta   DECIMAL(14,4) NOT NULL DEFAULT 0,
  costo_promedio DECIMAL(14,4) NOT NULL DEFAULT 0,
  stock_minimo   DECIMAL(14,4) NOT NULL DEFAULT 0,
  estado         TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_emp FOREIGN KEY (empresa_id)   REFERENCES empresas(id),
  CONSTRAINT fk_prod_cat FOREIGN KEY (categoria_id) REFERENCES categorias(id),
  CONSTRAINT fk_prod_mar FOREIGN KEY (marca_id)     REFERENCES marcas(id),
  CONSTRAINT fk_prod_uni FOREIGN KEY (unidad_id)    REFERENCES unidades(id),
  UNIQUE KEY uq_prod_emp_codigo (empresa_id, codigo),
  INDEX idx_prod_desc (descripcion),
  INDEX idx_prod_emp_cat (empresa_id, categoria_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock por producto+almacén  (RF-09). Ambos ya pertenecen a una empresa.
DROP TABLE IF EXISTS stock;
CREATE TABLE stock (
  producto_id INT UNSIGNED NOT NULL,
  almacen_id  INT UNSIGNED NOT NULL,
  cantidad    DECIMAL(14,4) NOT NULL DEFAULT 0,
  reservado   DECIMAL(14,4) NOT NULL DEFAULT 0,
  -- Costo unitario de las existencias EN ESTE ALMACÉN. Con promedio de ámbito
  -- global replica el costo del producto; con ámbito por almacén o con
  -- PEPS/UEPS es el costo propio de este almacén.
  costo_promedio DECIMAL(14,4) NOT NULL DEFAULT 0,
  actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (producto_id, almacen_id),
  CONSTRAINT fk_stock_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_alm  FOREIGN KEY (almacen_id)  REFERENCES almacenes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Movimientos: entradas y salidas  (RF-05, RF-06)
-- El correlativo se reinicia por empresa.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS entradas;
CREATE TABLE entradas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id     INT UNSIGNED NOT NULL,
  serie_numero   VARCHAR(40)  NOT NULL,
  fecha          DATE         NOT NULL,
  almacen_id     INT UNSIGNED NOT NULL,
  proveedor_id   INT UNSIGNED NULL,
  tipo_documento VARCHAR(30)  NULL,
  nro_documento  VARCHAR(40)  NULL,
  observacion    VARCHAR(255) NULL,
  total          DECIMAL(14,4) NOT NULL DEFAULT 0,
  estado         ENUM('BORRADOR','CONFIRMADO','ANULADO') NOT NULL DEFAULT 'CONFIRMADO',
  usuario_id     INT UNSIGNED NOT NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ent_emp  FOREIGN KEY (empresa_id)   REFERENCES empresas(id),
  CONSTRAINT fk_ent_alm  FOREIGN KEY (almacen_id)   REFERENCES almacenes(id),
  CONSTRAINT fk_ent_prov FOREIGN KEY (proveedor_id) REFERENCES proveedores(id),
  CONSTRAINT fk_ent_usr  FOREIGN KEY (usuario_id)   REFERENCES usuarios(id),
  UNIQUE KEY uq_ent_emp_serie (empresa_id, serie_numero),
  INDEX idx_ent_fecha (empresa_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS entrada_detalle;
CREATE TABLE entrada_detalle (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entrada_id     INT UNSIGNED NOT NULL,
  producto_id    INT UNSIGNED NOT NULL,
  cantidad       DECIMAL(14,4) NOT NULL,
  costo_unitario DECIMAL(14,4) NOT NULL DEFAULT 0,
  subtotal       DECIMAL(14,4) NOT NULL DEFAULT 0,
  CONSTRAINT fk_edet_ent  FOREIGN KEY (entrada_id)  REFERENCES entradas(id) ON DELETE CASCADE,
  CONSTRAINT fk_edet_prod FOREIGN KEY (producto_id) REFERENCES productos(id),
  INDEX idx_edet_prod (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS salidas;
CREATE TABLE salidas (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id   INT UNSIGNED NOT NULL,
  serie_numero VARCHAR(40)  NOT NULL,
  fecha        DATE         NOT NULL,
  almacen_id   INT UNSIGNED NOT NULL,
  motivo       VARCHAR(60)  NOT NULL,
  destino      VARCHAR(180) NULL,
  observacion  VARCHAR(255) NULL,
  total        DECIMAL(14,4) NOT NULL DEFAULT 0,
  estado       ENUM('BORRADOR','CONFIRMADO','ANULADO') NOT NULL DEFAULT 'CONFIRMADO',
  usuario_id   INT UNSIGNED NOT NULL,
  creado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sal_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  CONSTRAINT fk_sal_alm FOREIGN KEY (almacen_id) REFERENCES almacenes(id),
  CONSTRAINT fk_sal_usr FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  UNIQUE KEY uq_sal_emp_serie (empresa_id, serie_numero),
  INDEX idx_sal_fecha (empresa_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS salida_detalle;
CREATE TABLE salida_detalle (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  salida_id      INT UNSIGNED NOT NULL,
  producto_id    INT UNSIGNED NOT NULL,
  cantidad       DECIMAL(14,4) NOT NULL,
  costo_unitario DECIMAL(14,4) NOT NULL DEFAULT 0,
  subtotal       DECIMAL(14,4) NOT NULL DEFAULT 0,
  CONSTRAINT fk_sdet_sal  FOREIGN KEY (salida_id)   REFERENCES salidas(id) ON DELETE CASCADE,
  CONSTRAINT fk_sdet_prod FOREIGN KEY (producto_id) REFERENCES productos(id),
  INDEX idx_sdet_prod (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- KARDEX: libro inmutable de movimientos  (RF-08, RB-02, RB-04)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS kardex;
CREATE TABLE kardex (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id     INT UNSIGNED NOT NULL,
  producto_id    INT UNSIGNED NOT NULL,
  almacen_id     INT UNSIGNED NOT NULL,
  fecha          DATETIME     NOT NULL,
  tipo           ENUM('ENTRADA','SALIDA','AJUSTE_POS','AJUSTE_NEG','INV_INICIAL') NOT NULL,
  origen_tabla   VARCHAR(30)  NOT NULL,
  origen_id      INT UNSIGNED NOT NULL,
  documento      VARCHAR(60)  NULL,
  cantidad       DECIMAL(14,4) NOT NULL,
  costo_unitario DECIMAL(14,4) NOT NULL DEFAULT 0,
  saldo_cantidad DECIMAL(14,4) NOT NULL,
  saldo_costo    DECIMAL(14,4) NOT NULL DEFAULT 0,
  saldo_valor    DECIMAL(16,4) NOT NULL DEFAULT 0,
  motivo         VARCHAR(180) NULL,
  usuario_id     INT UNSIGNED NOT NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_kdx_emp  FOREIGN KEY (empresa_id)  REFERENCES empresas(id),
  CONSTRAINT fk_kdx_prod FOREIGN KEY (producto_id) REFERENCES productos(id),
  CONSTRAINT fk_kdx_alm  FOREIGN KEY (almacen_id)  REFERENCES almacenes(id),
  CONSTRAINT fk_kdx_usr  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
  INDEX idx_kdx_prod_fecha (producto_id, almacen_id, id),
  INDEX idx_kdx_emp_fecha (empresa_id, fecha),
  INDEX idx_kdx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Capas de costo — sólo se usan con PEPS y UEPS
--
-- Cada ingreso crea una capa con su costo. Cada salida consume capas: las más
-- antiguas primero en PEPS, las más recientes primero en UEPS. `cantidad_resta`
-- es lo que queda sin consumir de esa capa.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS capas_costo;
CREATE TABLE capas_costo (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id     INT UNSIGNED NOT NULL,
  producto_id    INT UNSIGNED NOT NULL,
  almacen_id     INT UNSIGNED NOT NULL,
  kardex_id      BIGINT UNSIGNED NULL,          -- movimiento que la originó
  fecha          DATETIME     NOT NULL,
  cantidad_ini   DECIMAL(14,4) NOT NULL,
  cantidad_resta DECIMAL(14,4) NOT NULL,
  costo_unitario DECIMAL(14,4) NOT NULL,
  documento      VARCHAR(60)  NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_capa_emp  FOREIGN KEY (empresa_id)  REFERENCES empresas(id),
  CONSTRAINT fk_capa_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  CONSTRAINT fk_capa_alm  FOREIGN KEY (almacen_id)  REFERENCES almacenes(id),
  -- Índice pensado para tomar las capas con saldo en orden de antigüedad.
  INDEX idx_capa_cola (producto_id, almacen_id, fecha, id),
  INDEX idx_capa_resta (producto_id, almacen_id, cantidad_resta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trazabilidad: qué capas consumió cada salida y a qué costo.
DROP TABLE IF EXISTS kardex_capa;
CREATE TABLE kardex_capa (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kardex_id      BIGINT UNSIGNED NOT NULL,
  capa_id        BIGINT UNSIGNED NOT NULL,
  cantidad       DECIMAL(14,4) NOT NULL,
  costo_unitario DECIMAL(14,4) NOT NULL,
  CONSTRAINT fk_kc_kardex FOREIGN KEY (kardex_id) REFERENCES kardex(id) ON DELETE CASCADE,
  CONSTRAINT fk_kc_capa   FOREIGN KEY (capa_id)   REFERENCES capas_costo(id),
  INDEX idx_kc_kardex (kardex_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Ajustes de inventario  (RF-07, RB-05)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS ajustes;
CREATE TABLE ajustes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id  INT UNSIGNED NOT NULL,
  fecha       DATE         NOT NULL,
  almacen_id  INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  tipo        ENUM('POSITIVO','NEGATIVO') NOT NULL,
  cantidad    DECIMAL(14,4) NOT NULL,
  motivo      VARCHAR(255) NOT NULL,
  usuario_id  INT UNSIGNED NOT NULL,
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_aj_emp  FOREIGN KEY (empresa_id)  REFERENCES empresas(id),
  CONSTRAINT fk_aj_alm  FOREIGN KEY (almacen_id)  REFERENCES almacenes(id),
  CONSTRAINT fk_aj_prod FOREIGN KEY (producto_id) REFERENCES productos(id),
  CONSTRAINT fk_aj_usr  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id),
  INDEX idx_aj_emp_fecha (empresa_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Inventario físico y conciliación  (RF-11)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS inventarios;
CREATE TABLE inventarios (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id  INT UNSIGNED NOT NULL,
  codigo      VARCHAR(30)  NOT NULL,
  fecha       DATE         NOT NULL,
  almacen_id  INT UNSIGNED NOT NULL,
  estado      ENUM('ABIERTO','CERRADO') NOT NULL DEFAULT 'ABIERTO',
  observacion VARCHAR(255) NULL,
  usuario_id  INT UNSIGNED NOT NULL,
  creado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cerrado_en  DATETIME NULL,
  CONSTRAINT fk_inv_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  CONSTRAINT fk_inv_alm FOREIGN KEY (almacen_id) REFERENCES almacenes(id),
  CONSTRAINT fk_inv_usr FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  UNIQUE KEY uq_inv_emp_codigo (empresa_id, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS inventario_detalle;
CREATE TABLE inventario_detalle (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inventario_id INT UNSIGNED NOT NULL,
  producto_id   INT UNSIGNED NOT NULL,
  stock_sistema DECIMAL(14,4) NOT NULL DEFAULT 0,
  stock_fisico  DECIMAL(14,4) NULL,
  diferencia    DECIMAL(14,4) NULL,
  conciliado    TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_idet_inv  FOREIGN KEY (inventario_id) REFERENCES inventarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_idet_prod FOREIGN KEY (producto_id)   REFERENCES productos(id),
  UNIQUE KEY uq_inv_prod (inventario_id, producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Líneas de producto de cada comprobante descargado
--
-- Lo que el SIRE no da y el inventario necesita. `producto_id` queda vacío
-- hasta que la conciliación lo empareja con un producto del catálogo.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS sunat_cpe_items;
CREATE TABLE sunat_cpe_items (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id     INT UNSIGNED NOT NULL,
  cpe_id         BIGINT UNSIGNED NOT NULL,
  linea          SMALLINT UNSIGNED NOT NULL,
  codigo_sunat   VARCHAR(60)  NULL,          -- código del emisor, si lo trae
  descripcion    VARCHAR(500) NOT NULL,
  cantidad       DECIMAL(14,4) NOT NULL,
  unidad_codigo  VARCHAR(10)  NULL,          -- NIU, KGM, BJ...
  unidad_nombre  VARCHAR(60)  NULL,
  valor_unitario DECIMAL(14,6) NOT NULL DEFAULT 0,   -- SIN IGV
  importe        DECIMAL(14,4) NOT NULL DEFAULT 0,
  producto_id    INT UNSIGNED NULL,          -- lo llena la conciliación
  CONSTRAINT fk_cpei_emp  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  CONSTRAINT fk_cpei_cpe  FOREIGN KEY (cpe_id)     REFERENCES sunat_comprobantes(id) ON DELETE CASCADE,
  CONSTRAINT fk_cpei_prod FOREIGN KEY (producto_id) REFERENCES productos(id),
  INDEX idx_cpei_cpe (cpe_id),
  INDEX idx_cpei_codigo (empresa_id, codigo_sunat),
  INDEX idx_cpei_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Equivalencias entre lo que dice SUNAT y el catálogo propio
--
-- La clave es el código del emisor (o la descripción si no lo trae) JUNTO CON
-- el RUC de quien lo emitió: el código 8863 del proveedor A no tiene nada que
-- ver con el 8863 del proveedor B. En las ventas el emisor es la propia
-- empresa, así que ahí el código ya es el del catálogo.
--
-- Se aprende una vez y se reutiliza en las importaciones siguientes.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS sunat_producto_mapa;
CREATE TABLE sunat_producto_mapa (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id  INT UNSIGNED NOT NULL,
  origen_ruc  VARCHAR(15)  NOT NULL,        -- RUC del emisor del código
  clave       VARCHAR(255) NOT NULL,        -- código del emisor, o la descripción
  producto_id INT UNSIGNED NULL,            -- NULL + ignorar=1 -> no es inventario
  ignorar     TINYINT(1)   NOT NULL DEFAULT 0,
  descripcion VARCHAR(500) NULL,            -- como la vio SUNAT, para la pantalla
  creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_map_emp  FOREIGN KEY (empresa_id)  REFERENCES empresas(id) ON DELETE CASCADE,
  CONSTRAINT fk_map_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  UNIQUE KEY uq_map (empresa_id, origen_ruc, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock inicial capturado antes de reproducir la historia (lo usa la fase 4).
DROP TABLE IF EXISTS sunat_stock_inicial;
CREATE TABLE sunat_stock_inicial (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Ejecuciones automáticas (cron)
--
-- Sin esto, cuando algo no aparece nadie sabe si el cron corrió, falló o
-- nunca estuvo configurado. También sirve de cerrojo: SUNAT rechaza dos
-- sesiones simultáneas del mismo RUC.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS sunat_tareas;
CREATE TABLE sunat_tareas (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Credenciales SUNAT por empresa
--
-- La Clave SOL y el client_secret se guardan CIFRADOS (AES-256-GCM, ver
-- app/Crypto.php); la clave maestra vive fuera de la base. El RUC, el usuario
-- SOL y el client_id no son secretos y se guardan en claro para poder
-- mostrarlos y diagnosticar.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS credenciales_sunat;
CREATE TABLE credenciales_sunat (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id     INT UNSIGNED NOT NULL,
  ruc            VARCHAR(11)  NOT NULL,
  usuario_sol    VARCHAR(60)  NOT NULL,
  clave_sol      VARCHAR(255) NOT NULL,          -- cifrada
  client_id      VARCHAR(120) NULL,
  client_secret  VARCHAR(255) NULL,              -- cifrado
  estado         ENUM('SIN_PROBAR','OK','ERROR') NOT NULL DEFAULT 'SIN_PROBAR',
  mensaje        VARCHAR(400) NULL,              -- resultado de la última prueba
  recursos       TEXT         NULL,              -- recursos del token, para diagnóstico
  verificado_en  DATETIME     NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cred_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  UNIQUE KEY uq_cred_empresa (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Comprobantes declarados en el SIRE
--
-- Es el "qué existe" según SUNAT: una fila por comprobante del período, con
-- datos de CABECERA (no trae el detalle de productos; eso llega al bajar el
-- comprobante). Se guarda para poder consultarlo sin volver a llamar a SUNAT
-- y para cruzarlo después con lo que ya se descargó.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS sunat_comprobantes;
CREATE TABLE sunat_comprobantes (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id     INT UNSIGNED NOT NULL,
  periodo        CHAR(6)      NOT NULL,          -- YYYYMM
  tipo           ENUM('ventas','compras') NOT NULL,
  cod_tipo_cdp   VARCHAR(4)   NULL,              -- 01 factura, 07 NC, 08 ND
  serie          VARCHAR(20)  NULL,
  numero         VARCHAR(30)  NULL,
  fecha_emision  DATE         NULL,
  ruc_contraparte    VARCHAR(15)  NULL,          -- cliente o proveedor
  nombre_contraparte VARCHAR(255) NULL,
  base_gravada   DECIMAL(14,2) NOT NULL DEFAULT 0,
  igv            DECIMAL(14,2) NOT NULL DEFAULT 0,
  total          DECIMAL(14,2) NOT NULL DEFAULT 0,
  moneda         VARCHAR(5)   NULL,
  estado_sunat   VARCHAR(40)  NULL,
  payload        MEDIUMTEXT   NULL,              -- respuesta cruda, por si falta un campo
  sincronizado_en DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Estado de la descarga del comprobante (fase 2)
  descarga_estado ENUM('PENDIENTE','OK','ERROR') NULL,
  descarga_msg   VARCHAR(250) NULL,
  descarga_intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  items_cant     SMALLINT UNSIGNED NULL,
  xml_path       VARCHAR(255) NULL,
  pdf_path       VARCHAR(255) NULL,
  cdr_path       VARCHAR(255) NULL,
  descargado_en  DATETIME     NULL,
  -- Fase 4: movimiento de inventario generado a partir de este comprobante
  mov_tabla      ENUM('entradas','salidas') NULL,
  mov_id         INT UNSIGNED NULL,
  mov_msg        VARCHAR(300) NULL,
  generado_en    DATETIME     NULL,

  CONSTRAINT fk_sc_emp FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  -- La clave natural necesita el TIPO de documento y el RUC del emisor:
  -- una factura y su nota de crédito comparten serie-número, y dos proveedores
  -- distintos pueden emitir la misma serie-número. Sin ambos, se pierden
  -- comprobantes en silencio (visto en vivo: 2 de 34 en un período real).
  UNIQUE KEY uq_sc (empresa_id, periodo, tipo, cod_tipo_cdp, serie, numero, ruc_contraparte),
  INDEX idx_sc_periodo (empresa_id, periodo, tipo),
  INDEX idx_sc_fecha (empresa_id, fecha_emision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Auditoría  (RF-15)
-- empresa_id puede ser NULL en eventos de sistema (login).
-- ------------------------------------------------------------
DROP TABLE IF EXISTS auditoria;
CREATE TABLE auditoria (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NULL,
  usuario_id INT UNSIGNED NULL,
  accion     VARCHAR(60)  NOT NULL,
  entidad    VARCHAR(60)  NOT NULL,
  entidad_id VARCHAR(40)  NULL,
  detalle    TEXT         NULL,
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(255) NULL,
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aud_empresa (empresa_id, creado_en),
  INDEX idx_aud_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
