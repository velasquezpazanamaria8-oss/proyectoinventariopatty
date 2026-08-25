# Subir el sistema al hosting (demostración a cliente)

Guía para publicar el proyecto en Hostinger con la base de demostración ya
cargada. Toma unos 15 minutos.

> ## ⚠ Lo primero: `debug => false`
>
> Si en `config.php` queda `'debug' => true`, cualquier error muestra al
> visitante **la ruta completa de sus archivos en el servidor** y fragmentos de
> las consultas, como en `/home/u000000/domains/…/app/DB.php`. Eso le dice a un
> atacante dónde está todo. Con `false` sólo se ve un mensaje genérico y el
> detalle queda en `storage/logs/php-error.log`.

---

## 1. Crear la base de datos

En el panel de Hostinger, **Bases de datos → MySQL**:

1. Crear una base nueva (por ejemplo `u123456_kardex`).
2. Crear un usuario y anotar **nombre de base, usuario y contraseña**.
   Hostinger antepone un prefijo al nombre que escriba: use el nombre completo.

## 2. Importar los datos

1. Abrir **phpMyAdmin** desde el panel y seleccionar la base recién creada.
2. Pestaña **Importar** → elegir `database/kardex_demo.sql` → **Continuar**.
3. Debe terminar sin errores y quedar con **24 tablas**.

El archivo trae estructura y datos: dos empresas, seis usuarios, 22 productos,
63 movimientos de kardex, inventarios físicos y auditoría.

> Si el archivo superara el límite de subida de phpMyAdmin, comprímalo en .zip:
> phpMyAdmin acepta el zip directamente.

## 3. Subir los archivos

Copiar por FTP o el Administrador de archivos **todo el proyecto** dentro de
`public_html` (o de la carpeta del subdominio), **excepto**:

```
database/            (no hace falta en el servidor)
storage/logs/*       (vacío)
.playwright-mcp/     (si existiera)
*.png                (capturas)
```

## 4. Configurar `config.php`

Es el único archivo que hay que editar. Cuatro cambios:

```php
'app' => [
    // null = la deduce sola comparando la carpeta del proyecto con la raíz
    // del servidor. Funciona tanto en la raíz del dominio como en subcarpeta.
    // Sólo fíjela a mano si su hosting hace algo raro con DOCUMENT_ROOT.
    'base_url' => null,
    'debug'    => false,       // IMPORTANTE: en true muestra rutas y errores internos
],
'db' => [
    'host'    => 'localhost',
    'nombre'  => 'u123456_kardex',     // el nombre completo que dio Hostinger
    'usuario' => 'u123456_admin',
    'clave'   => 'la-contraseña-de-la-base',
],
```

`debug => false` es el más importante: con `true`, cualquier error muestra
rutas del servidor y fragmentos de consulta a quien visite la página.

## 5. Borrar los archivos de instalación

**Antes de dar el enlace al cliente**, eliminar del servidor:

```
instalar.php        crea/borra la base entera
datos_demo.php      recarga los datos de demostración
migrar.php          altera el esquema
database/           incluye el volcado y el esquema
DESPLIEGUE.md       esta guía
```

Cualquiera de ellos, accesible por web, permite a un desconocido borrar o
reemplazar toda la base. `.htaccess` ya bloquea `database/`, pero lo seguro es
que no estén.

## 6. Comprobar que funciona

Entrar al sitio y verificar:

- La pantalla de acceso carga con estilos (si se ve sin formato, `base_url` está mal).
- Entra con **admin / admin123**.
- El panel muestra 18 productos, 4 en stock mínimo y 2 agotados.
- El kardex de **CAB-2001** muestra los movimientos con su costo promedio.
- Un reporte se descarga en PDF y en Excel.

## 7. Seguridad mínima antes de mostrarlo

- [ ] **Cambiar la contraseña de `admin`** desde Usuarios. `admin123` es pública
      en esta guía y el sitio estará en internet.
- [ ] Confirmar que `config.php` no se descarga: abrir
      `https://sudominio.com/config.php` debe dar **403**.
- [ ] Confirmar que `https://sudominio.com/instalar.php` da **404** (borrado).
- [ ] Activar HTTPS en Hostinger (SSL gratuito). Sin él, las contraseñas viajan
      en claro.

---

## Credenciales de la demostración

| Usuario | Clave | Rol | Para mostrar |
|---|---|---|---|
| `admin` | `admin123` | Superadmin | Todo, y el cambio entre las dos empresas |
| `jefe` | `demo123` | Administrador | Operación completa de una empresa |
| `almacen` | `demo123` | Almacenero | Registra movimientos, sin ver costos valorizados |
| `gerencia` | `demo123` | Gerencia | Sólo consulta |
| `contab` | `demo123` | Contabilidad | Consulta con reportes valorizados |
| `multi` | `demo123` | Dos empresas | Cambia de empresa y le cambia el rol |

## Guion sugerido para la demostración

1. **Panel** — alertas de stock mínimo y productos agotados.
2. **Productos** — buscar "cable", filtrar por categoría, ver stock por producto.
3. **Entradas → Nueva** — botón *Buscar producto*, agregar varios, registrar.
4. **Salidas → Nueva** — intentar sacar más de lo disponible: el sistema lo rechaza.
5. **Kardex de CAB-2001** — dos compras a distinto precio moviendo el costo promedio.
6. **Inventario físico** — un conteo abierto a medio contar y otro ya conciliado.
7. **Reportes** — generar el valorizado y descargarlo en PDF y Excel.
8. **Importar Excel** — descargar la plantilla y cargar productos en lote.
9. **Cambiar de empresa** (con `admin` o `multi`) — los datos son otros por completo.
10. **Auditoría** — todo lo anterior quedó registrado con usuario, fecha e IP.

Para el punto 5, *Comercial Andina* está configurada con **PEPS** en lugar de
promedio: sirve para mostrar que el método de valorización es configurable.

## Regenerar el volcado

Si cambia los datos en local y quiere subirlos de nuevo:

```bash
php datos_demo.php --forzar          # datos frescos, con fechas de hoy
php database/exportar_demo.php       # regenera database/kardex_demo.sql
```

Para que el volcado lleve otra contraseña de administrador:

```bash
php database/exportar_demo.php --clave=UnaClaveSegura
```
