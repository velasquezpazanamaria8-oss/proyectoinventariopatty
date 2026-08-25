<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Elegir empresa — <?= e(Config::get('app.nombre')) ?></title>
<?php $ver = function (string $r): string {
    $t = @filemtime(BASE_PATH . '/' . $r);
    return url($r) . ($t ? '?v=' . $t : '');
}; ?>
<link rel="stylesheet" href="<?= $ver('assets/css/app.css') ?>">
</head>
<body class="fondo-portada">

<header class="portada-cab">
  <div class="brand">
    <span class="brand-mark">K</span>
    <span class="brand-text">Kardex<small>Control de Inventarios</small></span>
  </div>
  <div class="usuario-box">
    <span class="usuario-nombre"><?= e(Auth::usuario()['nombres'] ?? '') ?></span>
    <span class="badge"><?= e(Auth::rol()) ?></span>
    <a class="btn btn-sm btn-gris" href="<?= url('logout.php') ?>">Salir</a>
  </div>
</header>

<main class="portada-cuerpo">
  <?php foreach (Sesion::flashes() as $f): ?>
    <div class="alerta alerta-<?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div>
  <?php endforeach; ?>

  <h1 class="portada-titulo">Elija la empresa</h1>
  <p class="portada-sub">
    Todo lo que registre después —productos, movimientos, comprobantes y cotizaciones—
    pertenece sólo a la empresa que elija. Son <strong><?= count($empresas) ?></strong> disponibles.
  </p>

  <div class="rejilla-empresas">
    <?php foreach ($empresas as $e):
      $id = (int) $e['id'];
      $r  = $resumen[$id];
      $esActual = $id === $actual; ?>
      <a class="tarjeta-empresa<?= $esActual ? ' actual' : '' ?>"
         href="<?= url('empresas.php?a=cambiar&id=' . $id) ?>">
        <div class="te-logo">
          <?php if ($r['logo']): ?>
            <img src="<?= url('empresa_logo.php?id=' . $id) ?>" alt="">
          <?php else: ?>
            <span class="te-inicial"><?= e(mb_strtoupper(mb_substr($e['nombre_corto'], 0, 2))) ?></span>
          <?php endif; ?>
        </div>

        <div class="te-cuerpo">
          <strong class="te-nombre"><?= e($e['razon_social']) ?></strong>
          <span class="te-ruc">RUC <?= e($e['ruc']) ?></span>
          <span class="te-datos">
            <?= $r['productos'] ?> producto<?= $r['productos'] === 1 ? '' : 's' ?>
            · <?= $r['cotiza'] ?> cotización<?= $r['cotiza'] === 1 ? '' : 'es' ?>
          </span>
        </div>

        <div class="te-pie">
          <?php if ($esActual): ?>
            <span class="badge badge-ok">Última usada</span>
          <?php else: ?>
            <span class="te-entrar">Entrar →</span>
          <?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>

    <?php if ($puedeCrear): ?>
      <a class="tarjeta-empresa te-nueva" href="<?= url('empresas.php') ?>">
        <span class="te-mas">+</span>
        <strong>Agregar empresa</strong>
        <span class="te-nueva-ayuda">Se crea con su sucursal, unidades,
          categoría y marca, lista para usar</span>
      </a>
    <?php endif; ?>
  </div>
</main>

<footer class="pie pie-contexto">
  <span><?= e(Config::get('app.nombre')) ?> · <?= date('Y') ?></span>
</footer>
</body>
</html>
