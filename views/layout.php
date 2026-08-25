<?php /** Layout principal. Variables: $tituloPagina, $contenido */ ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($tituloPagina) ?> — Kardex</title>
<?php /* Se cuelga la fecha del archivo del enlace: al subir una versión nueva
         el navegador la pide de verdad, en vez de servir la de su caché. */
$ver = function (string $ruta): string {
    $t = @filemtime(BASE_PATH . '/' . $ruta);
    return url($ruta) . ($t ? '?v=' . $t : '');
}; ?>
<link rel="stylesheet" href="<?= $ver('assets/css/app.css') ?>">
<?php /* app.js va en el <head> y sin defer: las vistas traen scripts en línea
         que usan sus funciones, y esos se ejecutan durante el parseo del body,
         antes que cualquier script colocado al final. */ ?>
<script>window.BASE_URL = <?= json_encode(Vista::url('')) ?>;</script>
<script src="<?= $ver('assets/js/app.js') ?>"></script>
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <span class="brand-mark">K</span>
      <span class="brand-text">Kardex<small>Control de Inventarios</small></span>
    </div>
    <div class="empresa-activa" title="<?= e(Empresa::actual()['razon_social'] ?? '') ?>">
      <span class="empresa-etiqueta">Empresa activa</span>
      <span class="empresa-nombre"><?= e(Empresa::nombre()) ?></span>
      <span class="empresa-ruc">RUC <?= e(Empresa::actual()['ruc'] ?? '') ?></span>
    </div>
    <nav class="menu">
      <a href="<?= url('index.php') ?>" class="<?= $_SERVER['SCRIPT_NAME'] === url('index.php') ? 'activo' : '' ?>">Panel</a>

      <?php if (Auth::puede('productos.ver')): ?>
        <span class="menu-grupo">Catálogo</span>
        <a href="<?= url('productos.php') ?>">Productos</a>
      <?php endif; ?>
      <?php if (Auth::puede('catalogos.ver')): ?>
        <a href="<?= url('catalogos.php?t=categorias') ?>">Categorías</a>
        <a href="<?= url('catalogos.php?t=marcas') ?>">Marcas</a>
        <a href="<?= url('catalogos.php?t=proveedores') ?>">Proveedores</a>
        <a href="<?= url('catalogos.php?t=clientes') ?>">Clientes</a>
        <a href="<?= url('catalogos.php?t=almacenes') ?>">Sucursales</a>
        <a href="<?= url('catalogos.php?t=unidades') ?>">Unidades</a>
      <?php endif; ?>

      <?php if (Auth::puede('entradas.ver') || Auth::puede('salidas.ver')): ?>
        <span class="menu-grupo">Movimientos</span>
      <?php endif; ?>
      <?php if (Auth::puede('entradas.ver')): ?><a href="<?= url('entradas.php') ?>">Entradas</a><?php endif; ?>
      <?php if (Auth::puede('salidas.ver')): ?><a href="<?= url('salidas.php') ?>">Salidas</a><?php endif; ?>
      <?php if (Auth::puede('ajustes.registrar')): ?><a href="<?= url('ajustes.php') ?>">Ajustes</a><?php endif; ?>
      <?php if (Auth::puede('kardex.ver')): ?><a href="<?= url('kardex.php') ?>">Kardex</a><?php endif; ?>

      <?php if (Auth::puede('cotizaciones.gestionar')): ?>
        <span class="menu-grupo">Ventas</span>
        <a href="<?= url('cotizaciones.php') ?>">Cotizaciones</a>
        <a href="<?= url('cotizacion_diseno.php') ?>">Diseño de cotización</a>
        <a href="<?= url('cotizacion_lienzo.php') ?>">Lienzo de cotización</a>
      <?php endif; ?>

      <span class="menu-grupo">Consultas</span>
      <?php if (Auth::puede('inventario.ver')): ?>
        <a href="<?= url('inventario.php') ?>">Stock actual</a>
        <a href="<?= url('inventario_fisico.php') ?>">Inventario físico</a>
      <?php endif; ?>
      <?php if (Auth::puede('reportes.ver')): ?><a href="<?= url('reportes.php') ?>">Reportes</a><?php endif; ?>
      <?php if (Auth::puede('usuarios.ver') || Auth::puede('sunat.gestionar')): ?>
        <span class="menu-grupo">Administración</span>
      <?php endif; ?>
      <?php if (Auth::puede('sunat.gestionar')): ?>
        <a href="<?= url('sunat.php') ?>">Conexión SUNAT</a>
        <a href="<?= url('sunat_comprobantes.php') ?>">Comprobantes SUNAT</a>
        <a href="<?= url('sunat_descargas.php') ?>">Descarga de CPE</a>
        <a href="<?= url('sunat_conciliar.php') ?>">Conciliar productos</a>
        <a href="<?= url('sunat_generar.php') ?>">Generar movimientos</a>
        <a href="<?= url('sunat_estado.php') ?>">Estado SUNAT</a>
      <?php endif; ?>
      <?php if (Auth::puede('usuarios.ver')): ?><a href="<?= url('usuarios.php') ?>">Usuarios</a><?php endif; ?>
      <?php if (Auth::puede('auditoria.ver')): ?><a href="<?= url('auditoria.php') ?>">Auditoría</a><?php endif; ?>
    </nav>
  </aside>

  <div class="contenedor">
    <header class="topbar">
      <button class="btn-icono" id="toggleMenu" aria-label="Menú">☰</button>
      <h1><?= e($tituloPagina) ?></h1>
      <div class="usuario-box">
        <?php $misEmpresas = Empresa::delUsuario(Auth::id()); ?>
        <?php if (count($misEmpresas) > 1): ?>
          <select class="selector-empresa" onchange="if(this.value) location.href=this.value">
            <?php foreach ($misEmpresas as $em): ?>
              <option value="<?= url('empresas.php?a=cambiar&id=' . $em['id']) ?>"
                      <?= (int) $em['id'] === Empresa::id() ? 'selected' : '' ?>>
                <?= e($em['nombre_corto']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php /* Vuelve a la portada, para verlas todas con su logo. */ ?>
          <a class="btn btn-sm btn-gris" href="<?= url('elegir_empresa.php') ?>"
             title="Ver todas las empresas">Cambiar</a>
        <?php endif; ?>
        <span class="usuario-nombre"><?= e(Auth::usuario()['nombres'] ?? '') ?></span>
        <span class="badge"><?= e(Auth::rol()) ?></span>
        <a class="btn btn-sm btn-gris" href="<?= url('logout.php') ?>">Salir</a>
      </div>
    </header>

    <main class="contenido">
      <?php foreach (Sesion::flashes() as $f): ?>
        <div class="alerta alerta-<?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div>
      <?php endforeach; ?>
      <?= $contenido ?>
    </main>

    <?php /* Recordatorio permanente de en qué empresa se está trabajando. Con
             nueve empresas es fácil perder la cuenta, y un movimiento en la
             equivocada cuesta mucho más de deshacer que de evitar. */ ?>
    <footer class="pie pie-contexto">
      <span><?= e(Config::get('app.nombre')) ?> · <?= date('Y') ?></span>
      <?php if (Empresa::hayActiva()): ?>
        <span>Empresa: <strong><?= e(Empresa::actual()['razon_social']) ?></strong>
          · RUC <?= e(Empresa::actual()['ruc']) ?></span>
      <?php endif; ?>
    </footer>
  </div>
</div>
</body>
</html>
