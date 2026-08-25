<?php /**
 * Layout de las pantallas de sistema: las que no pertenecen a una empresa en
 * concreto. Sin menú lateral, para que quede claro que lo que se ve no está
 * filtrado por la empresa activa.
 *
 * Variables: $tituloPagina, $contenido
 */ ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($tituloPagina) ?> — <?= e(Config::get('app.nombre')) ?></title>
<?php $ver = function (string $r): string {
    $t = @filemtime(BASE_PATH . '/' . $r);
    return url($r) . ($t ? '?v=' . $t : '');
}; ?>
<link rel="stylesheet" href="<?= $ver('assets/css/app.css') ?>">
<script>window.BASE_URL = <?= json_encode(Vista::url('')) ?>;</script>
<script src="<?= $ver('assets/js/app.js') ?>"></script>
</head>
<body class="fondo-portada">

<header class="portada-cab">
  <div class="brand">
    <span class="brand-mark">K</span>
    <span class="brand-text">Kardex<small>Control de Inventarios</small></span>
  </div>
  <div class="usuario-box">
    <a class="btn btn-sm btn-gris" href="<?= url('elegir_empresa.php') ?>">← Empresas</a>
    <span class="usuario-nombre"><?= e(Auth::usuario()['nombres'] ?? '') ?></span>
    <span class="badge"><?= e(Auth::rol()) ?></span>
    <a class="btn btn-sm btn-gris" href="<?= url('logout.php') ?>">Salir</a>
  </div>
</header>

<main class="portada-cuerpo">
  <?php foreach (Sesion::flashes() as $f): ?>
    <div class="alerta alerta-<?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div>
  <?php endforeach; ?>

  <h1 class="portada-titulo"><?= e($tituloPagina) ?></h1>
  <p class="portada-sub">
    Esta pantalla es de todo el sistema, no de una empresa: lo que ve aquí no está
    filtrado por la que tenga seleccionada.
  </p>

  <?= $contenido ?>
</main>

<footer class="pie pie-contexto">
  <span><?= e(Config::get('app.nombre')) ?> · <?= date('Y') ?></span>
</footer>
</body>
</html>
