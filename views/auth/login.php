<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingresar — <?= e(Config::get('app.nombre')) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>">
</head>
<body>
<div class="login-wrap">
  <form class="login-card" method="post" autocomplete="off">
    <h1><?= e(Config::get('app.nombre')) ?></h1>
    <p class="sub">Ingrese sus credenciales para continuar</p>

    <?php if ($error): ?><div class="alerta alerta-error"><?= e($error) ?></div><?php endif; ?>
    <?php foreach (Sesion::flashes() as $f): ?>
      <div class="alerta alerta-<?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div>
    <?php endforeach; ?>

    <?= Csrf::campo() ?>
    <div class="campo">
      <label for="usuario">Usuario</label>
      <input type="text" id="usuario" name="usuario" required autofocus
             value="<?= e($_POST['usuario'] ?? '') ?>">
    </div>
    <div class="campo" style="margin-top:12px">
      <label for="clave">Contraseña</label>
      <input type="password" id="clave" name="clave" required>
    </div>
    <button class="btn" type="submit">Ingresar</button>
  </form>
</div>
</body>
</html>
