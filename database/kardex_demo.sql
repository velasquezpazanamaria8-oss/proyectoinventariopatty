-- ============================================================
-- Sistema de Kardex e Inventarios — base de demostración
-- Estructura + datos, generado el 06/08/2026 17:11
--
-- Importar en phpMyAdmin sobre una base de datos VACÍA.
-- Compatible con MySQL 5.7+ y MariaDB 10.4+.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ------------------------------------------------------------
-- ESTRUCTURA
-- ------------------------------------------------------------
-- ============================================================
-- Sistema de Control de Kardex e Inventarios — MULTIEMPRESA
-- Motor: MySQL 8 / MariaDB 10.4+  (Laragon local, Hostinger prod)
--
-- Modelo de aislamiento: una sola base de datos con `empresa_id`
-- en cada tabla de negocio. TODA consulta debe filtrar por la
-- empresa activa (ver app/Empresa.php).
-- ============================================================




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

-- ------------------------------------------------------------
-- DATOS
-- ------------------------------------------------------------

-- empresas
INSERT INTO `empresas` (`id`,`ruc`,`razon_social`,`nombre_corto`,`direccion`,`telefono`,`email`,`moneda`,`simbolo`,`metodo_valorizacion`,`ambito_costo`,`estado`,`creado_en`) VALUES
(1,'20000000001','Empresa Demo S.A.C.','Empresa Demo',NULL,NULL,NULL,'PEN','S/','PROMEDIO','GLOBAL',1,'2026-08-06 17:11:38'),
(2,'20512345678','Comercial Andina E.I.R.L.','Comercial Andina','Av. Los Álamos 455, Arequipa','054-221100','ventas@comercialandina.pe','PEN','S/','PEPS','GLOBAL',1,'2026-08-06 17:11:38');

-- roles
INSERT INTO `roles` (`id`,`nombre`,`descripcion`,`global`,`creado_en`) VALUES
(1,'SUPERADMIN','Administra todas las empresas del sistema',1,'2026-08-06 17:11:38'),
(2,'ADMINISTRADOR','Acceso total dentro de su empresa',0,'2026-08-06 17:11:38'),
(3,'ALMACENERO','Registro de entradas, salidas y consultas',0,'2026-08-06 17:11:38'),
(4,'GERENCIA','Consulta de reportes',0,'2026-08-06 17:11:38'),
(5,'CONTABILIDAD','Consulta de reportes valorizados',0,'2026-08-06 17:11:38');

-- permisos
INSERT INTO `permisos` (`id`,`clave`,`descripcion`) VALUES
(1,'empresas.gestionar','Crear y editar empresas del sistema'),
(2,'usuarios.ver','Ver usuarios'),
(3,'usuarios.gestionar','Crear/editar/eliminar usuarios'),
(4,'catalogos.ver','Ver catálogos'),
(5,'catalogos.gestionar','Gestionar catálogos'),
(6,'productos.ver','Ver productos'),
(7,'productos.gestionar','Gestionar productos'),
(8,'entradas.ver','Ver entradas'),
(9,'entradas.registrar','Registrar entradas'),
(10,'salidas.ver','Ver salidas'),
(11,'salidas.registrar','Registrar salidas'),
(12,'ajustes.registrar','Registrar ajustes de inventario'),
(13,'kardex.ver','Consultar kardex'),
(14,'inventario.ver','Ver inventario'),
(15,'inventario.gestionar','Inventario físico y conciliación'),
(16,'reportes.ver','Ver reportes'),
(17,'reportes.valorizado','Ver reportes valorizados'),
(18,'auditoria.ver','Ver auditoría');

-- rol_permiso
INSERT INTO `rol_permiso` (`rol_id`,`permiso_id`) VALUES
(1,1),
(1,2),
(2,2),
(1,3),
(2,3),
(1,4),
(2,4),
(3,4),
(1,5),
(2,5),
(1,6),
(2,6),
(3,6),
(4,6),
(5,6),
(1,7),
(2,7),
(3,7),
(1,8),
(2,8),
(3,8),
(4,8),
(1,9),
(2,9),
(3,9),
(1,10),
(2,10),
(3,10),
(4,10),
(1,11),
(2,11),
(3,11),
(1,12),
(2,12),
(3,12),
(1,13),
(2,13),
(3,13),
(4,13),
(5,13),
(1,14),
(2,14),
(3,14),
(4,14),
(5,14),
(1,15),
(2,15),
(3,15),
(1,16),
(2,16);
INSERT INTO `rol_permiso` (`rol_id`,`permiso_id`) VALUES
(3,16),
(4,16),
(5,16),
(1,17),
(2,17),
(5,17),
(1,18),
(2,18);

-- usuarios
INSERT INTO `usuarios` (`id`,`usuario`,`nombres`,`email`,`password_hash`,`estado`,`ultimo_acceso`,`intentos_fallidos`,`bloqueado_hasta`,`creado_en`,`actualizado_en`) VALUES
(1,'admin','Administrador del Sistema','admin@kardex.local','$2y$10$nucO6.QqpT4afU2yRpzuDuxSKiRHJW0XF5laGzBrjknqVRHVdm7i2',1,NULL,0,NULL,'2026-08-06 17:11:38',NULL),
(2,'almacen','Rosa Quispe Mamani','rosa@demo.pe','$2y$10$GUbj0MwBlniMJ0aQ1GoP4OMnrb3KzWk55mCcIedf4WeEMFwMP131.',1,NULL,0,NULL,'2026-08-06 17:11:38',NULL),
(3,'gerencia','Carlos Rivera Soto','carlos@demo.pe','$2y$10$mL0RQWScPXjhTuguds9b1OQJfjsiaEcFVt/sRIhmOuemsQ.9Rn7/C',1,NULL,0,NULL,'2026-08-06 17:11:38',NULL),
(4,'contab','Lucía Fernández Paz','lucia@demo.pe','$2y$10$JGXgU5eoM7KClNY5mi27suk/.SFkqty0BEobPQAe9B/cEYZ4c1Lu.',1,NULL,0,NULL,'2026-08-06 17:11:38',NULL),
(5,'jefe','Miguel Ángel Torres','miguel@demo.pe','$2y$10$TjxEJbpA3tKAMtVgP/tZYO9Zt6eOZYsUqq3JoxhM.3KXqeYpi94si',1,NULL,0,NULL,'2026-08-06 17:11:39',NULL),
(6,'multi','Ana Salazar (dos empresas)','ana@demo.pe','$2y$10$SNax6j3xzNR7C3lHWwDfPO.pUDIxRanQ5xtc9RI1PWiptHVVUzWbW',1,NULL,0,NULL,'2026-08-06 17:11:39',NULL);

-- usuario_empresa
INSERT INTO `usuario_empresa` (`usuario_id`,`empresa_id`,`rol_id`,`por_defecto`) VALUES
(1,1,1,1),
(2,1,3,1),
(3,1,4,1),
(4,1,5,1),
(5,1,2,1),
(6,1,3,1),
(6,2,4,0);

-- categorias
INSERT INTO `categorias` (`id`,`empresa_id`,`nombre`,`descripcion`,`estado`,`creado_en`) VALUES
(1,1,'General','Categoría por defecto',1,'2026-08-06 17:11:38'),
(2,1,'Insumos','Insumos y materia prima',1,'2026-08-06 17:11:38'),
(3,1,'Repuestos','Repuestos y accesorios',1,'2026-08-06 17:11:38'),
(4,2,'General','Categoría por defecto',1,'2026-08-06 17:11:38'),
(5,1,'Ferretería','Tornillería, fijaciones y accesorios',1,'2026-08-06 17:11:39'),
(6,1,'Eléctricos','Cables, interruptores y material eléctrico',1,'2026-08-06 17:11:39'),
(7,1,'Pinturas','Pinturas, solventes y acabados',1,'2026-08-06 17:11:39'),
(8,1,'Herramientas','Herramientas manuales y eléctricas',1,'2026-08-06 17:11:39'),
(9,1,'Seguridad','Equipos de protección personal',1,'2026-08-06 17:11:39'),
(10,2,'Abarrotes','Productos de consumo',1,'2026-08-06 17:11:42');

-- marcas
INSERT INTO `marcas` (`id`,`empresa_id`,`nombre`,`estado`,`creado_en`) VALUES
(1,1,'SIN MARCA',1,'2026-08-06 17:11:38'),
(2,1,'GENÉRICO',1,'2026-08-06 17:11:38'),
(3,2,'SIN MARCA',1,'2026-08-06 17:11:38'),
(4,1,'Truper',1,'2026-08-06 17:11:39'),
(5,1,'Stanley',1,'2026-08-06 17:11:39'),
(6,1,'Indeco',1,'2026-08-06 17:11:39'),
(7,1,'Anypsa',1,'2026-08-06 17:11:39'),
(8,1,'Bosch',1,'2026-08-06 17:11:39'),
(9,1,'3M',1,'2026-08-06 17:11:39'),
(10,2,'Gloria',1,'2026-08-06 17:11:42');

-- unidades
INSERT INTO `unidades` (`id`,`empresa_id`,`codigo`,`nombre`,`decimales`) VALUES
(1,1,'UND','Unidad',0),
(2,1,'CJA','Caja',0),
(3,1,'KG','Kilogramo',3),
(4,1,'LT','Litro',3),
(5,1,'MT','Metro',2),
(6,1,'PQT','Paquete',0),
(7,2,'UND','Unidad',0),
(8,2,'CJA','Caja',0),
(9,2,'KG','Kilogramo',3),
(10,2,'LT','Litro',3),
(11,1,'GLN','Galón',2),
(12,1,'JGO','Juego',0),
(13,1,'ROL','Rollo',0);

-- proveedores
INSERT INTO `proveedores` (`id`,`empresa_id`,`ruc`,`razon_social`,`telefono`,`email`,`direccion`,`estado`,`creado_en`) VALUES
(1,1,'20100047218','Distribuidora Ferretera del Sur S.A.C.','054-234567','ventas@dfsur.pe','Av. Parra 1200, Arequipa',1,'2026-08-06 17:11:39'),
(2,1,'20456789123','Importaciones Eléctricas Perú S.A.','01-4567890','contacto@iep.pe','Jr. Paruro 890, Lima',1,'2026-08-06 17:11:40'),
(3,1,'10456123789','Pinturas y Acabados Andinos E.I.R.L.','054-445566','pedidos@paa.pe','Calle Mercaderes 220, Arequipa',1,'2026-08-06 17:11:40'),
(4,2,'20100123456','Mayorista Andina S.A.C.','054-990011',NULL,NULL,1,'2026-08-06 17:11:42');

-- almacenes
INSERT INTO `almacenes` (`id`,`empresa_id`,`codigo`,`nombre`,`direccion`,`estado`) VALUES
(1,1,'ALM-01','Almacén Principal','Sede central',1),
(2,2,'ALM-01','Almacén Principal',NULL,1),
(3,1,'ALM-02','Almacén Sucursal Cayma','Av. Cayma 780, Arequipa',1);

-- productos
INSERT INTO `productos` (`id`,`empresa_id`,`codigo`,`descripcion`,`categoria_id`,`marca_id`,`unidad_id`,`precio_compra`,`precio_venta`,`costo_promedio`,`stock_minimo`,`estado`,`creado_en`,`actualizado_en`) VALUES
(1,1,'TOR-1001','Tornillo hexagonal 1/2\" x 3\"',5,4,1,'0.7800','1.5000','0.7606','100.0000',1,'2026-08-06 17:11:40','2026-08-06 17:11:41'),
(2,1,'TOR-1002','Tornillo autorroscante 8 x 1\"',5,4,1,'0.3200','0.7000','0.3200','200.0000',1,'2026-08-06 17:11:40','2026-08-06 17:11:41'),
(3,1,'TUE-1003','Tuerca hexagonal 1/2\"',5,4,1,'0.4200','0.9000','0.4200','150.0000',1,'2026-08-06 17:11:40','2026-08-06 17:11:41'),
(4,1,'CLA-1004','Clavo de acero 2 1/2\"',5,2,3,'5.0000','8.5000','5.0000','20.0000',1,'2026-08-06 17:11:40','2026-08-06 17:11:41'),
(5,1,'CAB-2001','Cable THW 14 AWG',6,6,5,'2.4500','3.5000','2.2100','300.0000',1,'2026-08-06 17:11:40','2026-08-06 17:11:41'),
(6,1,'CAB-2002','Cable mellizo 2 x 18 AWG',6,6,5,'1.7000','2.9000','1.7000','250.0000',1,'2026-08-06 17:11:40','2026-08-06 17:11:41'),
(7,1,'INT-2003','Interruptor simple empotrar',6,8,1,'6.3000','11.0000','6.3000','40.0000',1,'2026-08-06 17:11:40','2026-08-06 17:11:41'),
(8,1,'TOM-2004','Tomacorriente doble con toma a tierra',6,8,1,'8.6000','15.0000','8.6000','40.0000',1,'2026-08-06 17:11:40','2026-08-06 17:11:41'),
(9,1,'FOC-2005','Foco LED 12W luz fría',6,2,1,'7.9000','12.5000','7.3600','60.0000',1,'2026-08-06 17:11:41','2026-08-06 17:11:41'),
(10,1,'PIN-3001','Pintura látex blanco',7,7,11,'38.5000','62.0000','37.5455','12.0000',1,'2026-08-06 17:11:41','2026-08-06 17:11:41'),
(11,1,'PIN-3002','Esmalte sintético negro',7,7,11,'41.0000','69.0000','41.0000','10.0000',1,'2026-08-06 17:11:41','2026-08-06 17:11:41'),
(12,1,'THI-3003','Thinner acrílico',7,7,11,'17.5000','29.0000','17.5000','15.0000',1,'2026-08-06 17:11:41','2026-08-06 17:11:41'),
(13,1,'BRO-3004','Brocha 4 pulgadas',7,4,1,'9.6000','16.0000','9.3633','25.0000',1,'2026-08-06 17:11:41','2026-08-06 17:11:41'),
(14,1,'TAL-4001','Taladro percutor 1/2\" 750W',8,8,1,'280.0000','420.0000','280.0000','3.0000',1,'2026-08-06 17:11:41','2026-08-06 17:11:41'),
(15,1,'JGO-4002','Juego de destornilladores 6 piezas',8,5,12,'43.5000','75.0000','43.5000','8.0000',1,'2026-08-06 17:11:41','2026-08-06 17:11:41'),
(16,1,'MAR-4003','Martillo carpintero 16 oz',8,5,1,'31.5000','52.0000','31.1744','10.0000',1,'2026-08-06 17:11:41','2026-08-06 17:11:41'),
(17,1,'CAS-5001','Casco de seguridad blanco',9,9,1,'24.5000','42.0000','24.1712','20.0000',1,'2026-08-06 17:11:41','2026-08-06 17:11:41'),
(18,1,'GUA-5002','Guantes de badana (par)',9,9,1,'8.2000','14.0000','8.2000','50.0000',1,'2026-08-06 17:11:41','2026-08-06 17:11:41'),
(19,1,'OBS-9001','Cinta aislante (descontinuado)',6,9,1,'2.5000','4.5000','0.0000','0.0000',0,'2026-08-06 17:11:41',NULL),
(20,2,'TOR-1001','Leche evaporada 400g (otra empresa)',10,10,7,'3.1000','4.5000','3.1000','24.0000',1,'2026-08-06 17:11:42','2026-08-06 17:11:42'),
(21,2,'ARZ-0001','Arroz extra saco 50kg',10,10,7,'171.0000','195.0000','165.7500','5.0000',1,'2026-08-06 17:11:42','2026-08-06 17:11:42'),
(22,2,'ACE-0002','Aceite vegetal 1L',10,10,7,'8.6000','10.5000','8.1974','30.0000',1,'2026-08-06 17:11:42','2026-08-06 17:11:42');

-- stock
INSERT INTO `stock` (`producto_id`,`almacen_id`,`cantidad`,`reservado`,`costo_promedio`,`actualizado_en`) VALUES
(1,1,'550.0000','0.0000','0.7606','2026-08-06 17:11:41'),
(1,3,'180.0000','0.0000','0.7606','2026-08-06 17:11:41'),
(2,1,'800.0000','0.0000','0.3200','2026-08-06 17:11:41'),
(3,1,'700.0000','0.0000','0.4200','2026-08-06 17:11:41'),
(4,1,'72.0000','0.0000','5.0000','2026-08-06 17:11:41'),
(5,1,'600.0000','0.0000','2.2100','2026-08-06 17:11:41'),
(6,1,'450.0000','0.0000','1.7000','2026-08-06 17:11:41'),
(7,1,'100.0000','0.0000','6.3000','2026-08-06 17:11:41'),
(8,1,'40.0000','0.0000','8.6000','2026-08-06 17:11:41'),
(9,1,'150.0000','0.0000','7.3600','2026-08-06 17:11:41'),
(10,1,'66.0000','0.0000','37.5455','2026-08-06 17:11:41'),
(11,1,'0.0000','0.0000','41.0000','2026-08-06 17:11:41'),
(12,1,'34.0000','0.0000','17.5000','2026-08-06 17:11:41'),
(13,1,'20.0000','0.0000','9.3633','2026-08-06 17:11:42'),
(14,1,'10.0000','0.0000','280.0000','2026-08-06 17:11:41'),
(15,1,'0.0000','0.0000','43.5000','2026-08-06 17:11:42'),
(16,1,'28.0000','0.0000','31.1744','2026-08-06 17:11:41'),
(16,3,'12.0000','0.0000','31.1744','2026-08-06 17:11:42'),
(17,1,'48.0000','0.0000','24.1712','2026-08-06 17:11:41'),
(17,3,'21.0000','0.0000','24.1712','2026-08-06 17:11:42'),
(18,1,'172.0000','0.0000','8.2000','2026-08-06 17:11:41'),
(20,2,'240.0000','0.0000','3.1000','2026-08-06 17:11:42'),
(21,2,'48.0000','0.0000','165.7500','2026-08-06 17:11:42'),
(22,2,'380.0000','0.0000','8.1974','2026-08-06 17:11:42');

-- entradas
INSERT INTO `entradas` (`id`,`empresa_id`,`serie_numero`,`fecha`,`almacen_id`,`proveedor_id`,`tipo_documento`,`nro_documento`,`observacion`,`total`,`estado`,`usuario_id`,`creado_en`) VALUES
(1,1,'E-000001','2026-06-12',1,1,'FACTURA','F001-004512','Compra a proveedor','2058.0000','CONFIRMADO',2,'2026-08-06 17:11:41'),
(2,1,'E-000002','2026-06-17',1,2,'FACTURA','F002-001188','Compra a proveedor','5797.0000','CONFIRMADO',2,'2026-08-06 17:11:41'),
(3,1,'E-000003','2026-06-22',1,3,'FACTURA','F003-000341','Compra a proveedor','5471.0000','CONFIRMADO',5,'2026-08-06 17:11:41'),
(4,1,'E-000004','2026-06-29',1,1,'GUIA','G001-000876','Compra a proveedor','5905.0000','CONFIRMADO',2,'2026-08-06 17:11:41'),
(5,1,'E-000005','2026-07-07',1,1,'FACTURA','F001-004698','Compra a proveedor','4340.0000','CONFIRMADO',2,'2026-08-06 17:11:41'),
(6,1,'E-000006','2026-07-19',1,2,'FACTURA','F002-001255','Compra a proveedor','2418.0000','CONFIRMADO',2,'2026-08-06 17:11:41'),
(7,1,'E-000007','2026-07-22',3,1,'GUIA','G001-000915','Compra a proveedor','1319.0000','CONFIRMADO',2,'2026-08-06 17:11:41'),
(8,1,'E-000008','2026-07-30',1,3,'BOLETA','B001-000077','Compra a proveedor','1308.0000','CONFIRMADO',2,'2026-08-06 17:11:41'),
(9,2,'E-000001','2026-07-27',2,4,'FACTURA','F100-000021','Compra inicial','9549.0000','CONFIRMADO',6,'2026-08-06 17:11:42'),
(10,2,'E-000002','2026-07-31',2,4,'FACTURA','F100-000034','Reposición','5140.0000','CONFIRMADO',6,'2026-08-06 17:11:42');

-- entrada_detalle
INSERT INTO `entrada_detalle` (`id`,`entrada_id`,`producto_id`,`cantidad`,`costo_unitario`,`subtotal`) VALUES
(1,1,1,'800.0000','0.7500','600.0000'),
(2,1,2,'1500.0000','0.3200','480.0000'),
(3,1,3,'900.0000','0.4200','378.0000'),
(4,1,4,'120.0000','5.0000','600.0000'),
(5,2,5,'1200.0000','2.0500','2460.0000'),
(6,2,6,'800.0000','1.7000','1360.0000'),
(7,2,7,'150.0000','6.3000','945.0000'),
(8,2,8,'120.0000','8.6000','1032.0000'),
(9,3,10,'60.0000','37.0000','2220.0000'),
(10,3,11,'40.0000','41.0000','1640.0000'),
(11,3,12,'50.0000','17.5000','875.0000'),
(12,3,13,'80.0000','9.2000','736.0000'),
(13,4,14,'12.0000','280.0000','3360.0000'),
(14,4,15,'30.0000','43.5000','1305.0000'),
(15,4,16,'40.0000','31.0000','1240.0000'),
(16,5,17,'60.0000','24.0000','1440.0000'),
(17,5,18,'200.0000','8.2000','1640.0000'),
(18,5,9,'180.0000','7.0000','1260.0000'),
(19,6,5,'600.0000','2.4500','1470.0000'),
(20,6,9,'120.0000','7.9000','948.0000'),
(21,7,1,'300.0000','0.7800','234.0000'),
(22,7,16,'15.0000','31.5000','472.5000'),
(23,7,17,'25.0000','24.5000','612.5000'),
(24,8,10,'24.0000','38.5000','924.0000'),
(25,8,13,'40.0000','9.6000','384.0000'),
(26,9,20,'240.0000','3.1000','744.0000'),
(27,9,21,'40.0000','162.0000','6480.0000'),
(28,9,22,'300.0000','7.7500','2325.0000'),
(29,10,22,'200.0000','8.6000','1720.0000'),
(30,10,21,'20.0000','171.0000','3420.0000');

-- salidas
INSERT INTO `salidas` (`id`,`empresa_id`,`serie_numero`,`fecha`,`almacen_id`,`motivo`,`destino`,`observacion`,`total`,`estado`,`usuario_id`,`creado_en`) VALUES
(1,1,'S-000001','2026-06-27',1,'VENTA','Constructora Los Andes S.A.C.',NULL,'886.5000','CONFIRMADO',2,'2026-08-06 17:11:41'),
(2,1,'S-000002','2026-07-02',1,'VENTA','Cliente mostrador',NULL,'1078.4000','CONFIRMADO',6,'2026-08-06 17:11:41'),
(3,1,'S-000003','2026-07-08',1,'CONSUMO','Área de mantenimiento',NULL,'616.0000','CONFIRMADO',2,'2026-08-06 17:11:41'),
(4,1,'S-000004','2026-07-09',1,'VENTA','Instalaciones Eléctricas RM',NULL,'1205.3000','CONFIRMADO',6,'2026-08-06 17:11:41'),
(5,1,'S-000005','2026-07-15',1,'MERMA','Producto deteriorado en bodega',NULL,'70.0000','CONFIRMADO',2,'2026-08-06 17:11:41'),
(6,1,'S-000006','2026-07-21',1,'VENTA','Ferretería El Constructor',NULL,'821.0000','CONFIRMADO',6,'2026-08-06 17:11:41'),
(7,1,'S-000007','2026-07-25',1,'CONSUMO','Taller de producción',NULL,'908.0000','CONFIRMADO',2,'2026-08-06 17:11:41'),
(8,1,'S-000008','2026-07-28',1,'VENTA','Constructora Los Andes S.A.C.',NULL,'3093.0000','CONFIRMADO',6,'2026-08-06 17:11:41'),
(9,1,'S-000009','2026-08-01',3,'VENTA','Cliente mostrador',NULL,'284.6416','CONFIRMADO',2,'2026-08-06 17:11:41'),
(10,1,'S-000010','2026-08-04',1,'DEVOLUCION','Devolución a proveedor',NULL,'205.0000','CONFIRMADO',6,'2026-08-06 17:11:41'),
(11,1,'S-000011','2026-08-05',1,'VENTA','Pedido mayorista Constructora Sur','Deja productos en stock mínimo y agotados','3483.5374','CONFIRMADO',6,'2026-08-06 17:11:41'),
(12,2,'S-000001','2026-08-03',2,'VENTA','Bodega San Martín',NULL,'2874.0000','CONFIRMADO',6,'2026-08-06 17:11:42');

-- salida_detalle
INSERT INTO `salida_detalle` (`id`,`salida_id`,`producto_id`,`cantidad`,`costo_unitario`,`subtotal`) VALUES
(1,1,1,'250.0000','0.7500','187.5000'),
(2,1,3,'200.0000','0.4200','84.0000'),
(3,1,5,'300.0000','2.0500','615.0000'),
(4,2,10,'18.0000','37.0000','666.0000'),
(5,2,13,'22.0000','9.2000','202.4000'),
(6,2,12,'12.0000','17.5000','210.0000'),
(7,3,18,'40.0000','8.2000','328.0000'),
(8,3,17,'12.0000','24.0000','288.0000'),
(9,4,6,'350.0000','1.7000','595.0000'),
(10,4,7,'45.0000','6.3000','283.5000'),
(11,4,8,'38.0000','8.6000','326.8000'),
(12,5,12,'4.0000','17.5000','70.0000'),
(13,6,2,'700.0000','0.3200','224.0000'),
(14,6,4,'45.0000','5.0000','225.0000'),
(15,6,16,'12.0000','31.0000','372.0000'),
(16,7,15,'8.0000','43.5000','348.0000'),
(17,7,14,'2.0000','280.0000','560.0000'),
(18,8,5,'900.0000','2.2100','1989.0000'),
(19,8,9,'150.0000','7.3600','1104.0000'),
(20,9,1,'120.0000','0.7606','91.2720'),
(21,9,17,'8.0000','24.1712','193.3696'),
(22,10,11,'5.0000','41.0000','205.0000'),
(23,11,8,'42.0000','8.6000','361.2000'),
(24,11,11,'35.0000','41.0000','1435.0000'),
(25,11,15,'22.0000','43.5000','957.0000'),
(26,11,13,'78.0000','9.3633','730.3374'),
(27,12,22,'120.0000','7.7500','930.0000'),
(28,12,21,'12.0000','162.0000','1944.0000');

-- kardex
INSERT INTO `kardex` (`id`,`empresa_id`,`producto_id`,`almacen_id`,`fecha`,`tipo`,`origen_tabla`,`origen_id`,`documento`,`cantidad`,`costo_unitario`,`saldo_cantidad`,`saldo_costo`,`saldo_valor`,`motivo`,`usuario_id`,`creado_en`) VALUES
(1,1,1,1,'2026-06-12 17:11:41','ENTRADA','entradas',1,'E-000001','800.0000','0.7500','800.0000','0.7500','600.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(2,1,2,1,'2026-06-12 17:11:41','ENTRADA','entradas',1,'E-000001','1500.0000','0.3200','1500.0000','0.3200','480.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(3,1,3,1,'2026-06-12 17:11:41','ENTRADA','entradas',1,'E-000001','900.0000','0.4200','900.0000','0.4200','378.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(4,1,4,1,'2026-06-12 17:11:41','ENTRADA','entradas',1,'E-000001','120.0000','5.0000','120.0000','5.0000','600.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(5,1,5,1,'2026-06-17 17:11:41','ENTRADA','entradas',2,'E-000002','1200.0000','2.0500','1200.0000','2.0500','2460.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(6,1,6,1,'2026-06-17 17:11:41','ENTRADA','entradas',2,'E-000002','800.0000','1.7000','800.0000','1.7000','1360.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(7,1,7,1,'2026-06-17 17:11:41','ENTRADA','entradas',2,'E-000002','150.0000','6.3000','150.0000','6.3000','945.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(8,1,8,1,'2026-06-17 17:11:41','ENTRADA','entradas',2,'E-000002','120.0000','8.6000','120.0000','8.6000','1032.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(9,1,10,1,'2026-06-22 17:11:41','ENTRADA','entradas',3,'E-000003','60.0000','37.0000','60.0000','37.0000','2220.0000','Compra a proveedor',5,'2026-08-06 17:11:41'),
(10,1,11,1,'2026-06-22 17:11:41','ENTRADA','entradas',3,'E-000003','40.0000','41.0000','40.0000','41.0000','1640.0000','Compra a proveedor',5,'2026-08-06 17:11:41'),
(11,1,12,1,'2026-06-22 17:11:41','ENTRADA','entradas',3,'E-000003','50.0000','17.5000','50.0000','17.5000','875.0000','Compra a proveedor',5,'2026-08-06 17:11:41'),
(12,1,13,1,'2026-06-22 17:11:41','ENTRADA','entradas',3,'E-000003','80.0000','9.2000','80.0000','9.2000','736.0000','Compra a proveedor',5,'2026-08-06 17:11:41'),
(13,1,1,1,'2026-06-27 17:11:41','SALIDA','salidas',1,'S-000001','250.0000','0.7500','550.0000','0.7500','412.5000','VENTA - Constructora Los Andes S.A.C.',2,'2026-08-06 17:11:41'),
(14,1,3,1,'2026-06-27 17:11:41','SALIDA','salidas',1,'S-000001','200.0000','0.4200','700.0000','0.4200','294.0000','VENTA - Constructora Los Andes S.A.C.',2,'2026-08-06 17:11:41'),
(15,1,5,1,'2026-06-27 17:11:41','SALIDA','salidas',1,'S-000001','300.0000','2.0500','900.0000','2.0500','1845.0000','VENTA - Constructora Los Andes S.A.C.',2,'2026-08-06 17:11:41'),
(16,1,14,1,'2026-06-29 17:11:41','ENTRADA','entradas',4,'E-000004','12.0000','280.0000','12.0000','280.0000','3360.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(17,1,15,1,'2026-06-29 17:11:41','ENTRADA','entradas',4,'E-000004','30.0000','43.5000','30.0000','43.5000','1305.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(18,1,16,1,'2026-06-29 17:11:41','ENTRADA','entradas',4,'E-000004','40.0000','31.0000','40.0000','31.0000','1240.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(19,1,10,1,'2026-07-02 17:11:41','SALIDA','salidas',2,'S-000002','18.0000','37.0000','42.0000','37.0000','1554.0000','VENTA - Cliente mostrador',6,'2026-08-06 17:11:41'),
(20,1,13,1,'2026-07-02 17:11:41','SALIDA','salidas',2,'S-000002','22.0000','9.2000','58.0000','9.2000','533.6000','VENTA - Cliente mostrador',6,'2026-08-06 17:11:41'),
(21,1,12,1,'2026-07-02 17:11:41','SALIDA','salidas',2,'S-000002','12.0000','17.5000','38.0000','17.5000','665.0000','VENTA - Cliente mostrador',6,'2026-08-06 17:11:41'),
(22,1,17,1,'2026-07-07 17:11:41','ENTRADA','entradas',5,'E-000005','60.0000','24.0000','60.0000','24.0000','1440.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(23,1,18,1,'2026-07-07 17:11:41','ENTRADA','entradas',5,'E-000005','200.0000','8.2000','200.0000','8.2000','1640.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(24,1,9,1,'2026-07-07 17:11:41','ENTRADA','entradas',5,'E-000005','180.0000','7.0000','180.0000','7.0000','1260.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(25,1,18,1,'2026-07-08 17:11:41','SALIDA','salidas',3,'S-000003','40.0000','8.2000','160.0000','8.2000','1312.0000','CONSUMO - Área de mantenimiento',2,'2026-08-06 17:11:41'),
(26,1,17,1,'2026-07-08 17:11:41','SALIDA','salidas',3,'S-000003','12.0000','24.0000','48.0000','24.0000','1152.0000','CONSUMO - Área de mantenimiento',2,'2026-08-06 17:11:41'),
(27,1,6,1,'2026-07-09 17:11:41','SALIDA','salidas',4,'S-000004','350.0000','1.7000','450.0000','1.7000','765.0000','VENTA - Instalaciones Eléctricas RM',6,'2026-08-06 17:11:41'),
(28,1,7,1,'2026-07-09 17:11:41','SALIDA','salidas',4,'S-000004','45.0000','6.3000','105.0000','6.3000','661.5000','VENTA - Instalaciones Eléctricas RM',6,'2026-08-06 17:11:41'),
(29,1,8,1,'2026-07-09 17:11:41','SALIDA','salidas',4,'S-000004','38.0000','8.6000','82.0000','8.6000','705.2000','VENTA - Instalaciones Eléctricas RM',6,'2026-08-06 17:11:41'),
(30,1,4,1,'2026-07-11 17:11:41','AJUSTE_NEG','ajustes',1,'AJU-000001','3.0000','5.0000','117.0000','5.0000','585.0000','Diferencia detectada en conteo parcial',5,'2026-08-06 17:11:41'),
(31,1,12,1,'2026-07-15 17:11:41','SALIDA','salidas',5,'S-000005','4.0000','17.5000','34.0000','17.5000','595.0000','MERMA - Producto deteriorado en bodega',2,'2026-08-06 17:11:41'),
(32,1,18,1,'2026-07-17 17:11:41','AJUSTE_POS','ajustes',2,'AJU-000002','12.0000','8.2000','172.0000','8.2000','1410.4000','Mercadería encontrada tras reordenar el almacén',5,'2026-08-06 17:11:41'),
(33,1,5,1,'2026-07-19 17:11:41','ENTRADA','entradas',6,'E-000006','600.0000','2.4500','1500.0000','2.2100','3315.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(34,1,9,1,'2026-07-19 17:11:41','ENTRADA','entradas',6,'E-000006','120.0000','7.9000','300.0000','7.3600','2208.0000','Compra a proveedor',2,'2026-08-06 17:11:41'),
(35,1,2,1,'2026-07-21 17:11:41','SALIDA','salidas',6,'S-000006','700.0000','0.3200','800.0000','0.3200','256.0000','VENTA - Ferretería El Constructor',6,'2026-08-06 17:11:41'),
(36,1,4,1,'2026-07-21 17:11:41','SALIDA','salidas',6,'S-000006','45.0000','5.0000','72.0000','5.0000','360.0000','VENTA - Ferretería El Constructor',6,'2026-08-06 17:11:41'),
(37,1,16,1,'2026-07-21 17:11:41','SALIDA','salidas',6,'S-000006','12.0000','31.0000','28.0000','31.0000','868.0000','VENTA - Ferretería El Constructor',6,'2026-08-06 17:11:41'),
(38,1,1,3,'2026-07-22 17:11:41','ENTRADA','entradas',7,'E-000007','300.0000','0.7800','300.0000','0.7606','228.1800','Compra a proveedor',2,'2026-08-06 17:11:41'),
(39,1,16,3,'2026-07-22 17:11:41','ENTRADA','entradas',7,'E-000007','15.0000','31.5000','15.0000','31.1744','467.6160','Compra a proveedor',2,'2026-08-06 17:11:41'),
(40,1,17,3,'2026-07-22 17:11:41','ENTRADA','entradas',7,'E-000007','25.0000','24.5000','25.0000','24.1712','604.2800','Compra a proveedor',2,'2026-08-06 17:11:41'),
(41,1,15,1,'2026-07-25 17:11:41','SALIDA','salidas',7,'S-000007','8.0000','43.5000','22.0000','43.5000','957.0000','CONSUMO - Taller de producción',2,'2026-08-06 17:11:41'),
(42,1,14,1,'2026-07-25 17:11:41','SALIDA','salidas',7,'S-000007','2.0000','280.0000','10.0000','280.0000','2800.0000','CONSUMO - Taller de producción',2,'2026-08-06 17:11:41'),
(43,1,5,1,'2026-07-28 17:11:41','SALIDA','salidas',8,'S-000008','900.0000','2.2100','600.0000','2.2100','1326.0000','VENTA - Constructora Los Andes S.A.C.',6,'2026-08-06 17:11:41'),
(44,1,9,1,'2026-07-28 17:11:41','SALIDA','salidas',8,'S-000008','150.0000','7.3600','150.0000','7.3600','1104.0000','VENTA - Constructora Los Andes S.A.C.',6,'2026-08-06 17:11:41'),
(45,1,10,1,'2026-07-30 17:11:41','ENTRADA','entradas',8,'E-000008','24.0000','38.5000','66.0000','37.5455','2478.0030','Compra a proveedor',2,'2026-08-06 17:11:41'),
(46,1,13,1,'2026-07-30 17:11:41','ENTRADA','entradas',8,'E-000008','40.0000','9.6000','98.0000','9.3633','917.6034','Compra a proveedor',2,'2026-08-06 17:11:41'),
(47,1,7,1,'2026-07-31 17:11:41','AJUSTE_NEG','ajustes',3,'AJU-000003','5.0000','6.3000','100.0000','6.3000','630.0000','Unidades falladas dadas de baja',5,'2026-08-06 17:11:41'),
(48,1,1,3,'2026-08-01 17:11:41','SALIDA','salidas',9,'S-000009','120.0000','0.7606','180.0000','0.7606','136.9080','VENTA - Cliente mostrador',2,'2026-08-06 17:11:41'),
(49,1,17,3,'2026-08-01 17:11:41','SALIDA','salidas',9,'S-000009','8.0000','24.1712','17.0000','24.1712','410.9104','VENTA - Cliente mostrador',2,'2026-08-06 17:11:41'),
(50,1,11,1,'2026-08-04 17:11:41','SALIDA','salidas',10,'S-000010','5.0000','41.0000','35.0000','41.0000','1435.0000','DEVOLUCION - Devolución a proveedor',6,'2026-08-06 17:11:41');
INSERT INTO `kardex` (`id`,`empresa_id`,`producto_id`,`almacen_id`,`fecha`,`tipo`,`origen_tabla`,`origen_id`,`documento`,`cantidad`,`costo_unitario`,`saldo_cantidad`,`saldo_costo`,`saldo_valor`,`motivo`,`usuario_id`,`creado_en`) VALUES
(51,1,8,1,'2026-08-05 17:11:41','SALIDA','salidas',11,'S-000011','42.0000','8.6000','40.0000','8.6000','344.0000','VENTA - Pedido mayorista Constructora Sur',6,'2026-08-06 17:11:41'),
(52,1,11,1,'2026-08-05 17:11:41','SALIDA','salidas',11,'S-000011','35.0000','41.0000','0.0000','41.0000','0.0000','VENTA - Pedido mayorista Constructora Sur',6,'2026-08-06 17:11:41'),
(53,1,15,1,'2026-08-05 17:11:42','SALIDA','salidas',11,'S-000011','22.0000','43.5000','0.0000','43.5000','0.0000','VENTA - Pedido mayorista Constructora Sur',6,'2026-08-06 17:11:42'),
(54,1,13,1,'2026-08-05 17:11:42','SALIDA','salidas',11,'S-000011','78.0000','9.3633','20.0000','9.3633','187.2660','VENTA - Pedido mayorista Constructora Sur',6,'2026-08-06 17:11:42'),
(55,1,17,3,'2026-08-02 17:11:42','AJUSTE_POS','ajustes',4,'INV-00001','4.0000','24.1712','21.0000','24.1712','507.5952','Conciliación de inventario físico INV-00001 (sistema 17 → contado 21)',6,'2026-08-06 17:11:42'),
(56,1,16,3,'2026-08-02 17:11:42','AJUSTE_NEG','ajustes',5,'INV-00001','3.0000','31.1744','12.0000','31.1744','374.0928','Conciliación de inventario físico INV-00001 (sistema 15 → contado 12)',6,'2026-08-06 17:11:42'),
(57,2,20,2,'2026-07-27 17:11:42','ENTRADA','entradas',9,'E-000001','240.0000','3.1000','240.0000','3.1000','744.0000','Compra inicial',6,'2026-08-06 17:11:42'),
(58,2,21,2,'2026-07-27 17:11:42','ENTRADA','entradas',9,'E-000001','40.0000','162.0000','40.0000','162.0000','6480.0000','Compra inicial',6,'2026-08-06 17:11:42'),
(59,2,22,2,'2026-07-27 17:11:42','ENTRADA','entradas',9,'E-000001','300.0000','7.7500','300.0000','7.7500','2325.0000','Compra inicial',6,'2026-08-06 17:11:42'),
(60,2,22,2,'2026-07-31 17:11:42','ENTRADA','entradas',10,'E-000002','200.0000','8.6000','500.0000','8.0900','4045.0000','Reposición',6,'2026-08-06 17:11:42'),
(61,2,21,2,'2026-07-31 17:11:42','ENTRADA','entradas',10,'E-000002','20.0000','171.0000','60.0000','165.0000','9900.0000','Reposición',6,'2026-08-06 17:11:42'),
(62,2,22,2,'2026-08-03 17:11:42','SALIDA','salidas',12,'S-000001','120.0000','7.7500','380.0000','8.1974','3115.0000','VENTA - Bodega San Martín',6,'2026-08-06 17:11:42'),
(63,2,21,2,'2026-08-03 17:11:42','SALIDA','salidas',12,'S-000001','12.0000','162.0000','48.0000','165.7500','7956.0000','VENTA - Bodega San Martín',6,'2026-08-06 17:11:42');

-- capas_costo
INSERT INTO `capas_costo` (`id`,`empresa_id`,`producto_id`,`almacen_id`,`kardex_id`,`fecha`,`cantidad_ini`,`cantidad_resta`,`costo_unitario`,`documento`,`creado_en`) VALUES
(1,2,20,2,57,'2026-07-27 17:11:42','240.0000','240.0000','3.1000','E-000001','2026-08-06 17:11:42'),
(2,2,21,2,58,'2026-07-27 17:11:42','40.0000','28.0000','162.0000','E-000001','2026-08-06 17:11:42'),
(3,2,22,2,59,'2026-07-27 17:11:42','300.0000','180.0000','7.7500','E-000001','2026-08-06 17:11:42'),
(4,2,22,2,60,'2026-07-31 17:11:42','200.0000','200.0000','8.6000','E-000002','2026-08-06 17:11:42'),
(5,2,21,2,61,'2026-07-31 17:11:42','20.0000','20.0000','171.0000','E-000002','2026-08-06 17:11:42');

-- kardex_capa
INSERT INTO `kardex_capa` (`id`,`kardex_id`,`capa_id`,`cantidad`,`costo_unitario`) VALUES
(1,62,3,'120.0000','7.7500'),
(2,63,2,'12.0000','162.0000');

-- ajustes
INSERT INTO `ajustes` (`id`,`empresa_id`,`fecha`,`almacen_id`,`producto_id`,`tipo`,`cantidad`,`motivo`,`usuario_id`,`creado_en`) VALUES
(1,1,'2026-07-11',1,4,'NEGATIVO','3.0000','Diferencia detectada en conteo parcial',5,'2026-08-06 17:11:41'),
(2,1,'2026-07-17',1,18,'POSITIVO','12.0000','Mercadería encontrada tras reordenar el almacén',5,'2026-08-06 17:11:41'),
(3,1,'2026-07-31',1,7,'NEGATIVO','5.0000','Unidades falladas dadas de baja',5,'2026-08-06 17:11:41'),
(4,1,'2026-08-02',3,17,'POSITIVO','4.0000','Conciliación de inventario físico INV-00001 (sistema 17 → contado 21)',6,'2026-08-06 17:11:42'),
(5,1,'2026-08-02',3,16,'NEGATIVO','3.0000','Conciliación de inventario físico INV-00001 (sistema 15 → contado 12)',6,'2026-08-06 17:11:42');

-- inventarios
INSERT INTO `inventarios` (`id`,`empresa_id`,`codigo`,`fecha`,`almacen_id`,`estado`,`observacion`,`usuario_id`,`creado_en`,`cerrado_en`) VALUES
(1,1,'INV-00001','2026-08-02',3,'CERRADO','Conteo trimestral de la sucursal',6,'2026-08-06 17:11:42','2026-08-06 17:11:42'),
(2,1,'INV-00002','2026-08-06',1,'ABIERTO','Conteo mensual en curso — quedan líneas por registrar',6,'2026-08-06 17:11:42',NULL);

-- inventario_detalle
INSERT INTO `inventario_detalle` (`id`,`inventario_id`,`producto_id`,`stock_sistema`,`stock_fisico`,`diferencia`,`conciliado`) VALUES
(1,1,1,'180.0000','180.0000','0.0000',1),
(2,1,16,'15.0000','12.0000','-3.0000',1),
(3,1,17,'17.0000','21.0000','4.0000',1),
(4,2,1,'550.0000',NULL,NULL,0),
(5,2,2,'800.0000',NULL,NULL,0),
(6,2,3,'700.0000',NULL,NULL,0),
(7,2,4,'72.0000','74.0000','2.0000',0),
(8,2,5,'600.0000','600.0000','0.0000',0),
(9,2,6,'450.0000','445.0000','-5.0000',0),
(10,2,7,'100.0000','100.0000','0.0000',0),
(11,2,8,'40.0000',NULL,NULL,0),
(12,2,9,'150.0000','145.0000','-5.0000',0),
(13,2,10,'66.0000','61.0000','-5.0000',0),
(14,2,12,'34.0000',NULL,NULL,0),
(15,2,13,'20.0000','22.0000','2.0000',0),
(16,2,14,'10.0000',NULL,NULL,0),
(17,2,16,'28.0000','30.0000','2.0000',0),
(18,2,17,'48.0000','48.0000','0.0000',0),
(19,2,18,'172.0000','172.0000','0.0000',0);

-- auditoria
INSERT INTO `auditoria` (`id`,`empresa_id`,`usuario_id`,`accion`,`entidad`,`entidad_id`,`detalle`,`ip`,`user_agent`,`creado_en`) VALUES
(1,1,1,'CREAR','empresas','2','{\"ruc\":\"20512345678\",\"razon_social\":\"Comercial Andina E.I.R.L.\",\"nombre_corto\":\"Comercial Andina\",\"direccion\":\"Av. Los Álamos 455, Arequipa\",\"telefono\":\"054-221100\",\"email\":\"ventas@comercialandina.pe\",\"moneda\":\"PEN\",\"simbolo\":\"S\\/\",\"estado\":1,\"metodo_valorizacion\":\"PEPS\",\"ambito_costo\":\"GLOBAL\"}',NULL,'','2026-08-06 17:11:38'),
(2,1,1,'CREAR','usuarios','2','{\"usuario\":\"almacen\"}',NULL,'','2026-08-06 17:11:38'),
(3,1,1,'CREAR','usuarios','3','{\"usuario\":\"gerencia\"}',NULL,'','2026-08-06 17:11:38'),
(4,1,1,'CREAR','usuarios','4','{\"usuario\":\"contab\"}',NULL,'','2026-08-06 17:11:38'),
(5,1,1,'CREAR','usuarios','5','{\"usuario\":\"jefe\"}',NULL,'','2026-08-06 17:11:39'),
(6,1,1,'CREAR','usuarios','6','{\"usuario\":\"multi\"}',NULL,'','2026-08-06 17:11:39'),
(7,1,1,'CREAR','categorias','5','{\"nombre\":\"Ferretería\",\"descripcion\":\"Tornillería, fijaciones y accesorios\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(8,1,1,'CREAR','categorias','6','{\"nombre\":\"Eléctricos\",\"descripcion\":\"Cables, interruptores y material eléctrico\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(9,1,1,'CREAR','categorias','7','{\"nombre\":\"Pinturas\",\"descripcion\":\"Pinturas, solventes y acabados\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(10,1,1,'CREAR','categorias','8','{\"nombre\":\"Herramientas\",\"descripcion\":\"Herramientas manuales y eléctricas\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(11,1,1,'CREAR','categorias','9','{\"nombre\":\"Seguridad\",\"descripcion\":\"Equipos de protección personal\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(12,1,1,'CREAR','marcas','4','{\"nombre\":\"Truper\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(13,1,1,'CREAR','marcas','5','{\"nombre\":\"Stanley\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(14,1,1,'CREAR','marcas','6','{\"nombre\":\"Indeco\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(15,1,1,'CREAR','marcas','7','{\"nombre\":\"Anypsa\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(16,1,1,'CREAR','marcas','8','{\"nombre\":\"Bosch\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(17,1,1,'CREAR','marcas','9','{\"nombre\":\"3M\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(18,1,1,'CREAR','unidades','11','{\"codigo\":\"GLN\",\"nombre\":\"Galón\",\"decimales\":2}',NULL,'','2026-08-06 17:11:39'),
(19,1,1,'CREAR','unidades','12','{\"codigo\":\"JGO\",\"nombre\":\"Juego\",\"decimales\":0}',NULL,'','2026-08-06 17:11:39'),
(20,1,1,'CREAR','unidades','13','{\"codigo\":\"ROL\",\"nombre\":\"Rollo\",\"decimales\":0}',NULL,'','2026-08-06 17:11:39'),
(21,1,1,'CREAR','proveedores','1','{\"ruc\":\"20100047218\",\"razon_social\":\"Distribuidora Ferretera del Sur S.A.C.\",\"telefono\":\"054-234567\",\"email\":\"ventas@dfsur.pe\",\"direccion\":\"Av. Parra 1200, Arequipa\",\"estado\":1}',NULL,'','2026-08-06 17:11:39'),
(22,1,1,'CREAR','proveedores','2','{\"ruc\":\"20456789123\",\"razon_social\":\"Importaciones Eléctricas Perú S.A.\",\"telefono\":\"01-4567890\",\"email\":\"contacto@iep.pe\",\"direccion\":\"Jr. Paruro 890, Lima\",\"estado\":1}',NULL,'','2026-08-06 17:11:40'),
(23,1,1,'CREAR','proveedores','3','{\"ruc\":\"10456123789\",\"razon_social\":\"Pinturas y Acabados Andinos E.I.R.L.\",\"telefono\":\"054-445566\",\"email\":\"pedidos@paa.pe\",\"direccion\":\"Calle Mercaderes 220, Arequipa\",\"estado\":1}',NULL,'','2026-08-06 17:11:40'),
(24,1,1,'CREAR','almacenes','3','{\"codigo\":\"ALM-02\",\"nombre\":\"Almacén Sucursal Cayma\",\"direccion\":\"Av. Cayma 780, Arequipa\",\"estado\":1}',NULL,'','2026-08-06 17:11:40'),
(25,1,1,'CREAR','productos','1','{\"codigo\":\"TOR-1001\",\"descripcion\":\"Tornillo hexagonal 1\\/2\\\" x 3\\\"\",\"categoria_id\":5,\"marca_id\":4,\"unidad_id\":1,\"precio_compra\":0.8,\"precio_venta\":1.5,\"stock_minimo\":100,\"estado\":1}',NULL,'','2026-08-06 17:11:40'),
(26,1,1,'CREAR','productos','2','{\"codigo\":\"TOR-1002\",\"descripcion\":\"Tornillo autorroscante 8 x 1\\\"\",\"categoria_id\":5,\"marca_id\":4,\"unidad_id\":1,\"precio_compra\":0.35,\"precio_venta\":0.7,\"stock_minimo\":200,\"estado\":1}',NULL,'','2026-08-06 17:11:40'),
(27,1,1,'CREAR','productos','3','{\"codigo\":\"TUE-1003\",\"descripcion\":\"Tuerca hexagonal 1\\/2\\\"\",\"categoria_id\":5,\"marca_id\":4,\"unidad_id\":1,\"precio_compra\":0.45,\"precio_venta\":0.9,\"stock_minimo\":150,\"estado\":1}',NULL,'','2026-08-06 17:11:40'),
(28,1,1,'CREAR','productos','4','{\"codigo\":\"CLA-1004\",\"descripcion\":\"Clavo de acero 2 1\\/2\\\"\",\"categoria_id\":5,\"marca_id\":2,\"unidad_id\":3,\"precio_compra\":5.2,\"precio_venta\":8.5,\"stock_minimo\":20,\"estado\":1}',NULL,'','2026-08-06 17:11:40'),
(29,1,1,'CREAR','productos','5','{\"codigo\":\"CAB-2001\",\"descripcion\":\"Cable THW 14 AWG\",\"categoria_id\":6,\"marca_id\":6,\"unidad_id\":5,\"precio_compra\":2.1,\"precio_venta\":3.5,\"stock_minimo\":300,\"estado\":1}',NULL,'','2026-08-06 17:11:40'),
(30,1,1,'CREAR','productos','6','{\"codigo\":\"CAB-2002\",\"descripcion\":\"Cable mellizo 2 x 18 AWG\",\"categoria_id\":6,\"marca_id\":6,\"unidad_id\":5,\"precio_compra\":1.75,\"precio_venta\":2.9,\"stock_minimo\":250,\"estado\":1}',NULL,'','2026-08-06 17:11:40'),
(31,1,1,'CREAR','productos','7','{\"codigo\":\"INT-2003\",\"descripcion\":\"Interruptor simple empotrar\",\"categoria_id\":6,\"marca_id\":8,\"unidad_id\":1,\"precio_compra\":6.5,\"precio_venta\":11,\"stock_minimo\":40,\"estado\":1}',NULL,'','2026-08-06 17:11:40'),
(32,1,1,'CREAR','productos','8','{\"codigo\":\"TOM-2004\",\"descripcion\":\"Tomacorriente doble con toma a tierra\",\"categoria_id\":6,\"marca_id\":8,\"unidad_id\":1,\"precio_compra\":8.9,\"precio_venta\":15,\"stock_minimo\":40,\"estado\":1}',NULL,'','2026-08-06 17:11:41'),
(33,1,1,'CREAR','productos','9','{\"codigo\":\"FOC-2005\",\"descripcion\":\"Foco LED 12W luz fría\",\"categoria_id\":6,\"marca_id\":2,\"unidad_id\":1,\"precio_compra\":7.2,\"precio_venta\":12.5,\"stock_minimo\":60,\"estado\":1}',NULL,'','2026-08-06 17:11:41'),
(34,1,1,'CREAR','productos','10','{\"codigo\":\"PIN-3001\",\"descripcion\":\"Pintura látex blanco\",\"categoria_id\":7,\"marca_id\":7,\"unidad_id\":11,\"precio_compra\":38,\"precio_venta\":62,\"stock_minimo\":12,\"estado\":1}',NULL,'','2026-08-06 17:11:41'),
(35,1,1,'CREAR','productos','11','{\"codigo\":\"PIN-3002\",\"descripcion\":\"Esmalte sintético negro\",\"categoria_id\":7,\"marca_id\":7,\"unidad_id\":11,\"precio_compra\":42.5,\"precio_venta\":69,\"stock_minimo\":10,\"estado\":1}',NULL,'','2026-08-06 17:11:41'),
(36,1,1,'CREAR','productos','12','{\"codigo\":\"THI-3003\",\"descripcion\":\"Thinner acrílico\",\"categoria_id\":7,\"marca_id\":7,\"unidad_id\":11,\"precio_compra\":18,\"precio_venta\":29,\"stock_minimo\":15,\"estado\":1}',NULL,'','2026-08-06 17:11:41'),
(37,1,1,'CREAR','productos','13','{\"codigo\":\"BRO-3004\",\"descripcion\":\"Brocha 4 pulgadas\",\"categoria_id\":7,\"marca_id\":4,\"unidad_id\":1,\"precio_compra\":9.5,\"precio_venta\":16,\"stock_minimo\":25,\"estado\":1}',NULL,'','2026-08-06 17:11:41'),
(38,1,1,'CREAR','productos','14','{\"codigo\":\"TAL-4001\",\"descripcion\":\"Taladro percutor 1\\/2\\\" 750W\",\"categoria_id\":8,\"marca_id\":8,\"unidad_id\":1,\"precio_compra\":285,\"precio_venta\":420,\"stock_minimo\":3,\"estado\":1}',NULL,'','2026-08-06 17:11:41'),
(39,1,1,'CREAR','productos','15','{\"codigo\":\"JGO-4002\",\"descripcion\":\"Juego de destornilladores 6 piezas\",\"categoria_id\":8,\"marca_id\":5,\"unidad_id\":12,\"precio_compra\":45,\"precio_venta\":75,\"stock_minimo\":8,\"estado\":1}',NULL,'','2026-08-06 17:11:41'),
(40,1,1,'CREAR','productos','16','{\"codigo\":\"MAR-4003\",\"descripcion\":\"Martillo carpintero 16 oz\",\"categoria_id\":8,\"marca_id\":5,\"unidad_id\":1,\"precio_compra\":32,\"precio_venta\":52,\"stock_minimo\":10,\"estado\":1}',NULL,'','2026-08-06 17:11:41'),
(41,1,1,'CREAR','productos','17','{\"codigo\":\"CAS-5001\",\"descripcion\":\"Casco de seguridad blanco\",\"categoria_id\":9,\"marca_id\":9,\"unidad_id\":1,\"precio_compra\":25,\"precio_venta\":42,\"stock_minimo\":20,\"estado\":1}',NULL,'','2026-08-06 17:11:41'),
(42,1,1,'CREAR','productos','18','{\"codigo\":\"GUA-5002\",\"descripcion\":\"Guantes de badana (par)\",\"categoria_id\":9,\"marca_id\":9,\"unidad_id\":1,\"precio_compra\":8.5,\"precio_venta\":14,\"stock_minimo\":50,\"estado\":1}',NULL,'','2026-08-06 17:11:41'),
(43,1,1,'CREAR','productos','19','{\"codigo\":\"OBS-9001\",\"descripcion\":\"Cinta aislante (descontinuado)\",\"categoria_id\":6,\"marca_id\":9,\"unidad_id\":1,\"precio_compra\":2.5,\"precio_venta\":4.5,\"stock_minimo\":0,\"estado\":0}',NULL,'','2026-08-06 17:11:41'),
(44,1,2,'CREAR','entradas','1','{\"serie\":\"E-000001\",\"total\":2058,\"items\":4}',NULL,'','2026-08-06 17:11:41'),
(45,1,2,'CREAR','entradas','2','{\"serie\":\"E-000002\",\"total\":5797,\"items\":4}',NULL,'','2026-08-06 17:11:41'),
(46,1,5,'CREAR','entradas','3','{\"serie\":\"E-000003\",\"total\":5471,\"items\":4}',NULL,'','2026-08-06 17:11:41'),
(47,1,2,'CREAR','salidas','1','{\"serie\":\"S-000001\",\"total\":886.5,\"items\":3}',NULL,'','2026-08-06 17:11:41'),
(48,1,2,'CREAR','entradas','4','{\"serie\":\"E-000004\",\"total\":5905,\"items\":3}',NULL,'','2026-08-06 17:11:41'),
(49,1,6,'CREAR','salidas','2','{\"serie\":\"S-000002\",\"total\":1078.4,\"items\":3}',NULL,'','2026-08-06 17:11:41'),
(50,1,2,'CREAR','entradas','5','{\"serie\":\"E-000005\",\"total\":4340,\"items\":3}',NULL,'','2026-08-06 17:11:41');
INSERT INTO `auditoria` (`id`,`empresa_id`,`usuario_id`,`accion`,`entidad`,`entidad_id`,`detalle`,`ip`,`user_agent`,`creado_en`) VALUES
(51,1,2,'CREAR','salidas','3','{\"serie\":\"S-000003\",\"total\":616,\"items\":2}',NULL,'','2026-08-06 17:11:41'),
(52,1,6,'CREAR','salidas','4','{\"serie\":\"S-000004\",\"total\":1205.3,\"items\":3}',NULL,'','2026-08-06 17:11:41'),
(53,1,5,'AJUSTE','ajustes','1','{\"fecha\":\"2026-07-11\",\"almacen_id\":1,\"producto_id\":4,\"tipo\":\"NEGATIVO\",\"cantidad\":3,\"costo_unitario\":0,\"motivo\":\"Diferencia detectada en conteo parcial\"}',NULL,'','2026-08-06 17:11:41'),
(54,1,2,'CREAR','salidas','5','{\"serie\":\"S-000005\",\"total\":70,\"items\":1}',NULL,'','2026-08-06 17:11:41'),
(55,1,5,'AJUSTE','ajustes','2','{\"fecha\":\"2026-07-17\",\"almacen_id\":1,\"producto_id\":18,\"tipo\":\"POSITIVO\",\"cantidad\":12,\"costo_unitario\":8.2,\"motivo\":\"Mercadería encontrada tras reordenar el almacén\"}',NULL,'','2026-08-06 17:11:41'),
(56,1,2,'CREAR','entradas','6','{\"serie\":\"E-000006\",\"total\":2418,\"items\":2}',NULL,'','2026-08-06 17:11:41'),
(57,1,6,'CREAR','salidas','6','{\"serie\":\"S-000006\",\"total\":821,\"items\":3}',NULL,'','2026-08-06 17:11:41'),
(58,1,2,'CREAR','entradas','7','{\"serie\":\"E-000007\",\"total\":1319,\"items\":3}',NULL,'','2026-08-06 17:11:41'),
(59,1,2,'CREAR','salidas','7','{\"serie\":\"S-000007\",\"total\":908,\"items\":2}',NULL,'','2026-08-06 17:11:41'),
(60,1,6,'CREAR','salidas','8','{\"serie\":\"S-000008\",\"total\":3093,\"items\":2}',NULL,'','2026-08-06 17:11:41'),
(61,1,2,'CREAR','entradas','8','{\"serie\":\"E-000008\",\"total\":1308,\"items\":2}',NULL,'','2026-08-06 17:11:41'),
(62,1,5,'AJUSTE','ajustes','3','{\"fecha\":\"2026-07-31\",\"almacen_id\":1,\"producto_id\":7,\"tipo\":\"NEGATIVO\",\"cantidad\":5,\"costo_unitario\":0,\"motivo\":\"Unidades falladas dadas de baja\"}',NULL,'','2026-08-06 17:11:41'),
(63,1,2,'CREAR','salidas','9','{\"serie\":\"S-000009\",\"total\":284.6416,\"items\":2}',NULL,'','2026-08-06 17:11:41'),
(64,1,6,'CREAR','salidas','10','{\"serie\":\"S-000010\",\"total\":205,\"items\":1}',NULL,'','2026-08-06 17:11:41'),
(65,1,6,'CREAR','salidas','11','{\"serie\":\"S-000011\",\"total\":3483.5373999999997,\"items\":4}',NULL,'','2026-08-06 17:11:42'),
(66,1,6,'ABRIR','inventarios','1','{\"codigo\":\"INV-00001\",\"almacen\":3,\"items\":3}',NULL,'','2026-08-06 17:11:42'),
(67,1,6,'CONTAR','inventarios','1','{\"lineas\":3}',NULL,'','2026-08-06 17:11:42'),
(68,1,6,'CERRAR','inventarios','1','{\"codigo\":\"INV-00001\",\"ajustes\":2,\"sin_cambio\":1}',NULL,'','2026-08-06 17:11:42'),
(69,1,6,'ABRIR','inventarios','2','{\"codigo\":\"INV-00002\",\"almacen\":1,\"items\":16}',NULL,'','2026-08-06 17:11:42'),
(70,1,6,'CONTAR','inventarios','2','{\"lineas\":10}',NULL,'','2026-08-06 17:11:42'),
(71,2,6,'CREAR','categorias','10','{\"nombre\":\"Abarrotes\",\"descripcion\":\"Productos de consumo\",\"estado\":1}',NULL,'','2026-08-06 17:11:42'),
(72,2,6,'CREAR','marcas','10','{\"nombre\":\"Gloria\",\"estado\":1}',NULL,'','2026-08-06 17:11:42'),
(73,2,6,'CREAR','proveedores','4','{\"ruc\":\"20100123456\",\"razon_social\":\"Mayorista Andina S.A.C.\",\"telefono\":\"054-990011\",\"email\":null,\"direccion\":null,\"estado\":1}',NULL,'','2026-08-06 17:11:42'),
(74,2,6,'CREAR','productos','20','{\"codigo\":\"TOR-1001\",\"descripcion\":\"Leche evaporada 400g (otra empresa)\",\"categoria_id\":10,\"marca_id\":10,\"unidad_id\":7,\"precio_compra\":3.2,\"precio_venta\":4.5,\"stock_minimo\":24,\"estado\":1}',NULL,'','2026-08-06 17:11:42'),
(75,2,6,'CREAR','productos','21','{\"codigo\":\"ARZ-0001\",\"descripcion\":\"Arroz extra saco 50kg\",\"categoria_id\":10,\"marca_id\":10,\"unidad_id\":7,\"precio_compra\":165,\"precio_venta\":195,\"stock_minimo\":5,\"estado\":1}',NULL,'','2026-08-06 17:11:42'),
(76,2,6,'CREAR','productos','22','{\"codigo\":\"ACE-0002\",\"descripcion\":\"Aceite vegetal 1L\",\"categoria_id\":10,\"marca_id\":10,\"unidad_id\":7,\"precio_compra\":7.9,\"precio_venta\":10.5,\"stock_minimo\":30,\"estado\":1}',NULL,'','2026-08-06 17:11:42'),
(77,2,6,'CREAR','entradas','9','{\"serie\":\"E-000001\",\"total\":9549,\"items\":3}',NULL,'','2026-08-06 17:11:42'),
(78,2,6,'CREAR','entradas','10','{\"serie\":\"E-000002\",\"total\":5140,\"items\":2}',NULL,'','2026-08-06 17:11:42'),
(79,2,6,'CREAR','salidas','12','{\"serie\":\"S-000001\",\"total\":2874,\"items\":2}',NULL,'','2026-08-06 17:11:42');

SET FOREIGN_KEY_CHECKS = 1;
