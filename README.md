# Sistema de Control de Kardex e Inventarios

Implementación en **PHP puro** (sin framework, sin Composer), **MySQL/MariaDB vía PDO** y
**frontend HTML/CSS/JS vanilla**, según `Requerimientos_Sistema_Kardex.docx`.

**Multiempresa**: una sola instalación atiende a varias empresas con datos
completamente separados. Ver la sección *Multiempresa* más abajo.

## Instalación (Laragon)

1. El proyecto va en `c:\laragon\www\proyectoinventariopatty` (ya está ahí).
2. Ajustar credenciales en `config.php` si su MySQL no es `root` sin contraseña.
3. Abrir `http://localhost/proyectoinventariopatty/instalar.php`
   (o ejecutar `php instalar.php`). Crea la BD, tablas y datos iniciales.
4. Entrar a `http://localhost/proyectoinventariopatty/` con **admin / admin123**.
5. Cambiar la contraseña y **eliminar `instalar.php`**.

Para reinstalar borrando todo: `instalar.php?forzar=1`.

### Datos de prueba

`datos_demo.php` (o `php datos_demo.php --forzar`) deja el sistema con un juego
de datos que ejercita **todas** las funciones: 2 empresas, usuarios de los 5
roles, 2 almacenes, 19 productos, 55 días de entradas/salidas/ajustes en orden
cronológico, productos en stock mínimo y agotados, y dos inventarios físicos
(uno cerrado y conciliado, otro abierto a medio contar). Al terminar imprime
las credenciales y una lista de qué probar.

Es un archivo de pruebas: **eliminar `datos_demo.php` e `instalar.php` antes de
publicar el sistema.**

## Estructura

```
bootstrap.php          Punto de entrada único (config + autoload + sesión)
config.php             Credenciales y parámetros
instalar.php           Instalador (eliminar tras usar)
datos_demo.php         Carga de datos de prueba (eliminar tras usar)
app/
  Config.php  DB.php  Sesion.php  Auth.php  Csrf.php  Vista.php
  Empresa.php    <- contexto multiempresa: filtro, sello y cambio de empresa
  Auditoria.php  Correlativo.php
  models/     Producto  Kardex  Entrada  Salida  Ajuste  Catalogo  Usuario
              Reporte  Inventario
  services/   Zip  Excel  Pdf  Exportador        <- escritura, sin dependencias
              ZipLector  ExcelLector             <- lectura de .xlsx
              ImportadorProductos                <- carga masiva
views/        layout.php + una carpeta por módulo
assets/       css/app.css   js/app.js
api/          productos.php (autocompletado)
database/     schema.sql   seed.sql
```

Todas las clases son estáticas y se invocan como `Clase::metodo()`.
El acceso a datos pasa siempre por `DB::` con *prepared statements*.

## Páginas

| Archivo | Módulo |
|---|---|
| `login.php` / `logout.php` | Autenticación |
| `index.php` | Panel con indicadores y alertas |
| `productos.php` | Productos (listar, crear, editar, eliminar) |
| `productos_importar.php` | Importación masiva desde Excel/CSV |
| `catalogos.php?t=…` | Categorías, marcas, unidades, proveedores, almacenes |
| `entradas.php` | Entradas de almacén |
| `salidas.php` | Salidas de almacén |
| `ajustes.php` | Ajustes positivos y negativos |
| `kardex.php` | Kardex general y por producto |
| `inventario.php` | Stock actual: físico / reservado / disponible |
| `inventario_fisico.php` | Conteos físicos y conciliación |
| `exportar.php` | Descarga de cualquier reporte en PDF o Excel |
| `reportes.php` | Los reportes del punto 8 del documento |
| `empresas.php` | Empresas del sistema y cambio de empresa activa |
| `usuarios.php` | Usuarios y asignación de rol por empresa |
| `auditoria.php` | Historial de acciones |

## Multiempresa

**Modelo:** una sola base de datos con `empresa_id` en cada tabla de negocio
(categorías, marcas, unidades, proveedores, almacenes, productos, entradas,
salidas, ajustes, kardex, inventarios y auditoría). Se eligió sobre "una base
por empresa" porque en hosting compartido (Hostinger) crear bases por cliente
tiene tope, y aquí dar de alta una empresa es instantáneo, con un solo respaldo
y una sola actualización de esquema.

**Cómo se garantiza el aislamiento**

1. `Empresa::filtro()` / `Empresa::param()` / `Empresa::sello()` centralizan el
   filtro en [app/Empresa.php](app/Empresa.php). Los modelos no escriben el
   `WHERE empresa_id` a mano: lo componen desde ahí.
2. `Empresa::id()` **lanza excepción** si no hay empresa activa, en vez de
   devolver 0 y consultar sin filtro.
3. `Kardex::registrar()` revalida contra la BD que el producto y el almacén
   pertenezcan a la empresa activa antes de mover stock. Es la última barrera:
   aunque un formulario manipulado enviara un `producto_id` ajeno, el
   movimiento se rechaza.
4. `Empresa::activar()` nunca confía en el id que llega del navegador: relee
   los vínculos del usuario y sólo cambia la sesión si tiene acceso real.

**Roles y usuarios**

- El login (`usuarios.usuario`) es único a nivel de sistema; el vínculo con cada
  empresa vive en `usuario_empresa`, **con un rol distinto por empresa**. Al
  cambiar de empresa se recargan los permisos: el mismo usuario puede ser
  almacenero en una y gerencia en otra.
- El rol `SUPERADMIN` (`roles.global = 1`) ve todas las empresas y es el único
  que puede crearlas y asignar roles globales.
- Al crear una empresa se generan sus catálogos base (almacén principal,
  unidades, una categoría y una marca) para que sea operable de inmediato.

**Qué es único por empresa y qué no**

| Único por empresa | Único en todo el sistema |
|---|---|
| Código de producto, categoría, marca, unidad, almacén, RUC de proveedor | Nombre de usuario y email |
| Correlativos `E-000001` / `S-000001` (reinician en cada empresa) | RUC de empresa |

**Verificado en ejecución:** dos empresas en paralelo no se ven productos,
kardex, reportes ni usuarios entre sí; el mismo código de producto convive en
ambas; los correlativos reinician; una entrada que referencia un producto de
otra empresa es rechazada; y un usuario sin vínculo no puede activar una
empresa ajena ni por URL directa.

## Trazabilidad de requerimientos

| RF | Dónde |
|---|---|
| RF-01 Inicio de sesión | `Auth::intentarLogin` + bloqueo por intentos fallidos |
| RF-02 Gestión de usuarios y permisos | `usuarios.php`, `Usuario`, tablas `roles`/`permisos`/`rol_permiso` |
| RF-03 Gestión de productos | `productos.php`, `Producto` |
| RF-04 Gestión de categorías | `catalogos.php`, `Catalogo` |
| RF-05 Entradas + kardex + stock | `Entrada::registrar` → `Kardex::registrar` |
| RF-06 Salidas con validación de stock | `Salida::registrar` (excepción si no alcanza) |
| RF-07 Ajustes ± | `Ajuste::registrar` (motivo obligatorio) |
| RF-08 Kardex por producto | `kardex.php?producto_id=…` |
| RF-09 Stock actual/reservado/disponible | `inventario.php`, `Reporte::stockActual` |
| RF-10 Alertas de stock mínimo/agotado | Panel + `Producto::stockMinimo` |
| RF-11 Inventario físico y conciliación | `inventario_fisico.php`, `Inventario` |
| RF-12 Reportes PDF y Excel | `exportar.php`, `Pdf`, `Excel`, `Zip`, `Exportador` |
| RF-13 Búsqueda | Filtros en cada listado + `api/productos.php` |
| RF-14 Exportación | `exportarCSV()` en `assets/js/app.js` |
| RF-15 Auditoría | `Auditoria`, `auditoria.php` |

Reglas de negocio: no hay salidas sin stock (validación en `Kardex::registrar` con
`SELECT … FOR UPDATE` dentro de transacción), todo movimiento queda en `kardex`,
el stock se actualiza en la misma transacción, los movimientos no se eliminan
(los productos con historial se desactivan) y el ajuste exige motivo.

## Métodos de valorización

El kardex es de **inventario permanente**: cada movimiento actualiza el saldo al
instante y queda registrado con el saldo de cantidad, el costo unitario y el
valor resultantes. Cada empresa elige **cómo se valoriza una salida**:

| Método | Cómo valoriza la salida |
|---|---|
| **Promedio ponderado — global** | Un costo por producto, recalculado en cada compra sumando todos los almacenes |
| **Promedio ponderado — por almacén** | Igual, pero cada almacén lleva su propio costo |
| **PEPS** (FIFO) | Al costo de las compras más antiguas |
| **UEPS** (LIFO) | Al costo de las compras más recientes |

Se configura en `empresas.php` y **sólo puede elegirse antes del primer
movimiento**: cambiarlo después dejaría un kardex con dos criterios mezclados,
así que el sistema lo bloquea.

**PEPS y UEPS trabajan con capas de costo** (`capas_costo`): cada ingreso crea
una capa y cada salida las consume en orden, dejando registrado en
`kardex_capa` qué capas se consumieron y a qué costo. El kardex de cada
producto muestra las capas pendientes. Las capas son siempre por almacén,
porque una capa es mercadería que está físicamente en un sitio.

Verificado con el ejemplo clásico —compra 100 a 10.00, compra 100 a 20.00,
vende 150—:

| Método | Valor de la salida | Existencias que quedan |
|---|---|---|
| Promedio | 2,250.00 | 50 u a 15.00 = 750.00 |
| PEPS | 2,000.00 | 50 u a 20.00 = 1,000.00 |
| UEPS | 2,500.00 | 50 u a 10.00 = 500.00 |

**Nota contable:** UEPS no está aceptado por las NIIF. Se incluye porque el
sistema puede usarse con fines de gestión interna, pero para estados
financieros conviene promedio o PEPS.

### Compatibilidad MySQL / MariaDB

El desarrollo local corre sobre MySQL 8 y el hosting sobre MariaDB, que es más
estricta. Dos diferencias ya nos costaron un error en producción y conviene
tenerlas presentes al escribir consultas nuevas:

1. **No referenciar el alias de una función de agregación en `HAVING`.**
   MySQL lo acepta, MariaDB responde *"Reference not supported (reference to
   group function)"*. Hay que repetir la expresión:
   `HAVING COALESCE(SUM(s.cantidad), 0) <= pr.stock_minimo`.
2. **Literales SQL siempre en comillas simples.** Con `ANSI_QUOTES` activo, las
   comillas dobles se interpretan como nombre de columna y la consulta falla.

Para comprobar ambas cosas antes de subir, active temporalmente el modo
estricto en `app/DB.php`:

```php
SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ANSI_QUOTES'
```

y recorra las pantallas. Recuerde revertirlo después.

### Actualizar una base ya instalada

`migrar.php` agrega las columnas y tablas nuevas sin perder datos. Es
idempotente. Las empresas existentes quedan en promedio con ámbito global.

## Importación masiva de productos

En `productos_importar.php` (botón *Importar Excel* del listado de productos).
Acepta **.xlsx** y **.csv**, en dos pasos: se sube el archivo, se muestra una
vista previa validada fila por fila y sólo al confirmar se escribe.

- La cabecera **no** tiene que estar en la primera fila ni con un orden fijo:
  se detecta buscando las columnas conocidas, tolerando tildes, mayúsculas y
  sinónimos (`Código`/`codigo`/`SKU`, `Descripción`/`Producto`/`Nombre`, …).
  Sólo `Código` y `Descripción` son obligatorias.
- Los números de fila que se reportan son los **reales del archivo**, para que
  el usuario encuentre el error en Excel aunque haya filas en blanco.
- Categorías, marcas y unidades se resuelven por nombre sin distinguir
  mayúsculas ni tildes —`Genérico` reutiliza `GENÉRICO`, no lo duplica— y hay
  una opción para crear las que falten.
- El **stock inicial** no se escribe directo en la tabla: genera un movimiento
  `INV_INICIAL` en el kardex, respetando la regla de que toda existencia viene
  de un movimiento (RB-02, RB-03). Sólo se aplica a productos nuevos, para que
  reimportar el mismo archivo no duplique existencias.
- Toda la carga es **una sola transacción**: si una fila falla a mitad de
  camino, no queda un catálogo a medias ni productos sueltos.
- El archivo subido se guarda en `storage/importaciones` (denegado por
  `.htaccess`), se referencia por un token en sesión —no por una ruta enviada
  por el navegador— y se borra al terminar; los restos de más de 2 horas se
  limpian solos.

Lectura de `.xlsx` sin `ZipArchive`: [ZipLector.php](app/services/ZipLector.php)
recorre el directorio central y descomprime con `gzinflate`;
[ExcelLector.php](app/services/ExcelLector.php) resuelve cadenas compartidas,
texto en línea y la hoja real declarada en `workbook.xml`.

Límite: 5000 filas y 8 MB por carga. El formato `.xls` (Excel 97-2003) no está
soportado; hay que guardar como `.xlsx` o `.csv`.

## Inventario físico y conciliación (RF-11)

Flujo en tres pasos, en `inventario_fisico.php`:

1. **Abrir conteo** — congela (`stock_sistema`) el stock que el sistema tiene en
   ese momento para cada producto del almacén. Se puede limitar a productos con
   stock distinto de cero. Sólo se admite **un conteo abierto por almacén**.
2. **Registrar lo contado** — la pantalla recalcula diferencia, impacto valorizado
   y estado (sobrante / faltante / coincide) mientras se escribe, con filtros por
   pendientes o por diferencias y buscador de filas.
3. **Cerrar y conciliar** — por cada diferencia ≠ 0 genera un ajuste (positivo o
   negativo) y su movimiento de kardex, dejando el stock igual a lo contado. Todo
   en una sola transacción.

Decisiones que conviene conocer:

- Las líneas **sin contar se ignoran** al cerrar: no se asume que estén en cero.
  Un producto contado explícitamente en 0 sí genera el ajuste hasta dejarlo en cero.
- Un conteo cerrado **no se reabre** — el kardex es inmutable (RB-04). Para
  corregir se registra un ajuste nuevo.
- Cada ajuste generado lleva el motivo trazable
  `Conciliación de inventario físico INV-00001 (sistema 50 → contado 55)`
  y usa el código del conteo como documento en el kardex.
- Un conteo abierto se puede **anular** sin tocar el stock.

## PDF y Excel nativos (RF-12)

Sin Composer y sin `ZipArchive` (que no está en todos los hostings), los tres
generadores están escritos a mano:

| Clase | Qué hace |
|---|---|
| [app/services/Zip.php](app/services/Zip.php) | Contenedor ZIP byte a byte: cabecera local, deflate con `gzdeflate`, directorio central |
| [app/services/Excel.php](app/services/Excel.php) | Paquete OpenXML `.xlsx`: cadenas compartidas, estilos, anchos, panel congelado, autofiltro y fila de totales |
| [app/services/Pdf.php](app/services/Pdf.php) | PDF 1.4 con fuentes base (Helvetica, sin incrustar), cabecera con datos de la empresa, tabla paginada y pie numerado |
| [app/services/Exportador.php](app/services/Exportador.php) | Declara **una sola vez** las columnas de cada reporte y produce ambos formatos, para que no se desincronicen |

Todo pasa por `exportar.php?r=<reporte>&f=pdf|xlsx`, que arrastra los filtros
vigentes de la pantalla y **revalida el permiso del reporte** (un rol sin
`reportes.valorizado` no puede descargarlo escribiendo la URL a mano).

En Excel los números se escriben como celdas numéricas reales —la hoja es
calculable, no texto—; en PDF se formatean como texto alineado a la derecha.

**Verificado con herramientas independientes:** los `.xlsx` los abre `zipfile`
de Python con CRC correcto y XML válido; los `.pdf` los lee Poppler
(`pdftotext`), con paginación correcta (5 páginas en una prueba de 200 filas) y
acentos intactos (0 caracteres corruptos).

## Pendiente para las siguientes iteraciones

- Reserva de stock (`stock.reservado` se muestra pero aún nada lo alimenta).
- Anulación de entradas/salidas con movimiento de reversión en kardex.
- Multi-almacén con traslados entre almacenes.
- Multiempresa: pantalla para vincular un usuario **existente** a otra empresa
  (`Usuario::vincularAEmpresa` ya existe, falta la UI) y logo por empresa en los
  documentos impresos.
- Cron de respaldo automático y almacenamiento de comprobantes en Cloudflare R2.
