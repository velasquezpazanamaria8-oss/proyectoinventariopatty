<?php /** Layout principal. Variables: $tituloPagina, $contenido */ ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($tituloPagina) ?> — Kardex</title>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
<?php /* app.js va en el <head> y sin defer: las vistas traen scripts en línea
         que usan sus funciones, y esos se ejecutan durante el parseo del body,
         antes que cualquier script colocado al final. */ ?>
<script>window.BASE_URL = <?= json_encode(Vista::url('')) ?>;</script>
<script src="<?= url('assets/js/app.js') ?>"></script>
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
        <a href="<?= url('catalogos.php?t=almacenes') ?>">Almacenes</a>
        <a href="<?= url('catalogos.php?t=unidades') ?>">Unidades</a>
      <?php endif; ?>

      <?php if (Auth::puede('entradas.ver') || Auth::puede('salidas.ver')): ?>
        <span class="menu-grupo">Movimientos</span>
      <?php endif; ?>
      <?php if (Auth::puede('entradas.ver')): ?><a href="<?= url('entradas.php') ?>">Entradas</a><?php endif; ?>
      <?php if (Auth::puede('salidas.ver')): ?><a href="<?= url('salidas.php') ?>">Salidas</a><?php endif; ?>
      <?php if (Auth::puede('ajustes.registrar')): ?><a href="<?= url('ajustes.php') ?>">Ajustes</a><?php endif; ?>
      <?php if (Auth::puede('kardex.ver')): ?><a href="<?= url('kardex.php') ?>">Kardex</a><?php endif; ?>

      <span class="menu-grupo">Consultas</span>
      <?php if (Auth::puede('inventario.ver')): ?>
        <a href="<?= url('inventario.php') ?>">Stock actual</a>
        <a href="<?= url('inventario_fisico.php') ?>">Inventario físico</a>
      <?php endif; ?>
      <?php if (Auth::puede('reportes.ver')): ?><a href="<?= url('reportes.php') ?>">Reportes</a><?php endif; ?>
      <?php if (Auth::puede('usuarios.ver') || Auth::puede('empresas.gestionar')): ?>
        <span class="menu-grupo">Administración</span>
      <?php endif; ?>
      <?php if (Auth::puede('empresas.gestionar')): ?><a href="<?= url('empresas.php') ?>">Empresas</a><?php endif; ?>
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

    <footer class="pie">
      <?= e(Config::get('app.nombre')) ?> · <?= date('Y') ?>
    </footer>
  </div>
</div>
</body>
</html>
