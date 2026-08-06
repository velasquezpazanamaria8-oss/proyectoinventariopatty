-- Datos iniciales del Sistema de Kardex (multiempresa)
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Empresa inicial
-- ------------------------------------------------------------
INSERT INTO empresas (id, ruc, razon_social, nombre_corto, moneda, simbolo) VALUES
 (1,'20000000001','Empresa Demo S.A.C.','Empresa Demo','PEN','S/');

-- ------------------------------------------------------------
-- Roles. `global` = 1 significa que el rol ve todas las empresas.
-- ------------------------------------------------------------
INSERT INTO roles (id, nombre, descripcion, global) VALUES
 (1,'SUPERADMIN','Administra todas las empresas del sistema',1),
 (2,'ADMINISTRADOR','Acceso total dentro de su empresa',0),
 (3,'ALMACENERO','Registro de entradas, salidas y consultas',0),
 (4,'GERENCIA','Consulta de reportes',0),
 (5,'CONTABILIDAD','Consulta de reportes valorizados',0);

INSERT INTO permisos (clave, descripcion) VALUES
 ('empresas.gestionar','Crear y editar empresas del sistema'),
 ('usuarios.ver','Ver usuarios'),('usuarios.gestionar','Crear/editar/eliminar usuarios'),
 ('catalogos.ver','Ver catálogos'),('catalogos.gestionar','Gestionar catálogos'),
 ('productos.ver','Ver productos'),('productos.gestionar','Gestionar productos'),
 ('entradas.ver','Ver entradas'),('entradas.registrar','Registrar entradas'),
 ('salidas.ver','Ver salidas'),('salidas.registrar','Registrar salidas'),
 ('ajustes.registrar','Registrar ajustes de inventario'),
 ('kardex.ver','Consultar kardex'),
 ('inventario.ver','Ver inventario'),('inventario.gestionar','Inventario físico y conciliación'),
 ('reportes.ver','Ver reportes'),('reportes.valorizado','Ver reportes valorizados'),
 ('auditoria.ver','Ver auditoría');

-- Superadmin: todos los permisos, incluido el de empresas
INSERT INTO rol_permiso (rol_id, permiso_id) SELECT 1, id FROM permisos;
-- Administrador de empresa: todo menos crear empresas
INSERT INTO rol_permiso (rol_id, permiso_id) SELECT 2, id FROM permisos WHERE clave <> 'empresas.gestionar';
-- Almacenero
INSERT INTO rol_permiso (rol_id, permiso_id) SELECT 3, id FROM permisos WHERE clave IN
 ('catalogos.ver','productos.ver','productos.gestionar','entradas.ver','entradas.registrar',
  'salidas.ver','salidas.registrar','ajustes.registrar','kardex.ver','inventario.ver',
  'inventario.gestionar','reportes.ver');
-- Gerencia
INSERT INTO rol_permiso (rol_id, permiso_id) SELECT 4, id FROM permisos WHERE clave IN
 ('productos.ver','entradas.ver','salidas.ver','kardex.ver','inventario.ver','reportes.ver');
-- Contabilidad
INSERT INTO rol_permiso (rol_id, permiso_id) SELECT 5, id FROM permisos WHERE clave IN
 ('productos.ver','kardex.ver','inventario.ver','reportes.ver','reportes.valorizado');

-- ------------------------------------------------------------
-- Usuario inicial -> admin / admin123  (CAMBIAR EN PRODUCCIÓN)
-- ------------------------------------------------------------
INSERT INTO usuarios (id, usuario, nombres, email, password_hash, estado) VALUES
 (1,'admin','Administrador del Sistema','admin@kardex.local',
  '$2y$10$nucO6.QqpT4afU2yRpzuDuxSKiRHJW0XF5laGzBrjknqVRHVdm7i2', 1);

INSERT INTO usuario_empresa (usuario_id, empresa_id, rol_id, por_defecto) VALUES (1, 1, 1, 1);

-- ------------------------------------------------------------
-- Catálogos base de la empresa 1
-- ------------------------------------------------------------
INSERT INTO unidades (empresa_id, codigo, nombre, decimales) VALUES
 (1,'UND','Unidad',0),(1,'CJA','Caja',0),(1,'KG','Kilogramo',3),
 (1,'LT','Litro',3),(1,'MT','Metro',2),(1,'PQT','Paquete',0);

INSERT INTO almacenes (empresa_id, codigo, nombre, direccion) VALUES
 (1,'ALM-01','Almacén Principal','Sede central');

INSERT INTO categorias (empresa_id, nombre, descripcion) VALUES
 (1,'General','Categoría por defecto'),
 (1,'Insumos','Insumos y materia prima'),
 (1,'Repuestos','Repuestos y accesorios');

INSERT INTO marcas (empresa_id, nombre) VALUES (1,'SIN MARCA'),(1,'GENÉRICO');
