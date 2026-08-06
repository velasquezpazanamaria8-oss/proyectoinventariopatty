<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2><?= $editar ? 'Editar empresa' : 'Nueva empresa' ?></h2>
    <?php if ($editar): ?><a class="btn btn-sm btn-gris" href="<?= url('empresas.php') ?>">Cancelar edición</a><?php endif; ?>
  </div>
  <form method="post" class="tarjeta-cuerpo">
    <?= Csrf::campo() ?>
    <input type="hidden" name="id" value="<?= e($editar['id'] ?? '') ?>">
    <div class="form-grid">
      <div class="campo">
        <label>RUC / identificación fiscal *</label>
        <input type="text" name="ruc" required maxlength="20" value="<?= e($editar['ruc'] ?? '') ?>">
      </div>
      <div class="campo" style="grid-column:span 2">
        <label>Razón social *</label>
        <input type="text" name="razon_social" required maxlength="180" value="<?= e($editar['razon_social'] ?? '') ?>">
      </div>
      <div class="campo">
        <label>Nombre corto</label>
        <input type="text" name="nombre_corto" maxlength="60" value="<?= e($editar['nombre_corto'] ?? '') ?>"
               placeholder="Se muestra en la barra superior">
      </div>
      <div class="campo">
        <label>Teléfono</label>
        <input type="text" name="telefono" maxlength="30" value="<?= e($editar['telefono'] ?? '') ?>">
      </div>
      <div class="campo">
        <label>Email</label>
        <input type="email" name="email" maxlength="150" value="<?= e($editar['email'] ?? '') ?>">
      </div>
      <div class="campo" style="grid-column:span 2">
        <label>Dirección</label>
        <input type="text" name="direccion" maxlength="255" value="<?= e($editar['direccion'] ?? '') ?>">
      </div>
      <div class="campo">
        <label>Moneda</label>
        <input type="text" name="moneda" maxlength="10" value="<?= e($editar['moneda'] ?? 'PEN') ?>">
      </div>
      <div class="campo">
        <label>Símbolo</label>
        <input type="text" name="simbolo" maxlength="5" value="<?= e($editar['simbolo'] ?? 'S/') ?>">
      </div>
      <div class="campo">
        <label>Estado</label>
        <select name="estado">
          <option value="1" <?= (int) ($editar['estado'] ?? 1) === 1 ? 'selected' : '' ?>>Activa</option>
          <option value="0" <?= (int) ($editar['estado'] ?? 1) === 0 ? 'selected' : '' ?>>Inactiva</option>
        </select>
      </div>
    </div>

    <?php
      $bloqueado = $editar && !Valorizacion::puedeCambiarMetodo((int) $editar['id']);
      $metodoAct = $editar['metodo_valorizacion'] ?? Valorizacion::PROMEDIO;
      $ambitoAct = $editar['ambito_costo'] ?? Valorizacion::AMBITO_GLOBAL;
    ?>
    <h3 style="margin-top:20px">Valorización del inventario</h3>
    <div class="alerta <?= $bloqueado ? 'alerta-warning' : 'alerta-info' ?>">
      <?php if ($bloqueado): ?>
        Esta empresa ya tiene movimientos registrados, así que el método
        <strong><?= e(Valorizacion::METODOS[$metodoAct]) ?></strong> queda fijo.
        Cambiarlo ahora mezclaría dos criterios en el mismo kardex y las
        existencias dejarían de ser comparables con lo ya registrado.
      <?php else: ?>
        Define a qué costo salen las unidades del almacén. Sólo puede elegirse
        <strong>antes del primer movimiento</strong>: después queda fijo.
      <?php endif; ?>
    </div>

    <div class="form-grid">
      <div class="campo">
        <label>Método *</label>
        <select name="metodo_valorizacion" id="metodoVal" <?= $bloqueado ? 'disabled' : '' ?>>
          <?php foreach (Valorizacion::METODOS as $clave => $texto): ?>
            <option value="<?= e($clave) ?>" <?= $metodoAct === $clave ? 'selected' : '' ?>><?= e($texto) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo" id="campoAmbito">
        <label>Ámbito del costo promedio</label>
        <select name="ambito_costo" <?= $bloqueado ? 'disabled' : '' ?>>
          <option value="GLOBAL"  <?= $ambitoAct === 'GLOBAL'  ? 'selected' : '' ?>>Global — un costo por producto</option>
          <option value="ALMACEN" <?= $ambitoAct === 'ALMACEN' ? 'selected' : '' ?>>Por almacén — un costo en cada almacén</option>
        </select>
      </div>
      <div class="campo" style="grid-column:span 2">
        <label>&nbsp;</label>
        <p style="margin:0;color:var(--suave);font-size:12.5px" id="ayudaMetodo"></p>
      </div>
    </div>
    <?php if (!$editar): ?>
      <p style="color:var(--suave);font-size:12.5px;margin-top:12px">
        Al crear la empresa se generan automáticamente su almacén principal, unidades de medida,
        una categoría y una marca por defecto, para que pueda operar de inmediato.
        Los datos de cada empresa quedan completamente separados de las demás.
      </p>
    <?php endif; ?>
    <div class="acciones" style="margin-top:12px">
      <button class="btn" type="submit"><?= $editar ? 'Actualizar' : 'Crear empresa' ?></button>
    </div>
  </form>
</div>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Empresas registradas (<?= count($empresas) ?>)</h2>
    <form method="get" style="display:flex;gap:6px">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar empresa...">
      <button class="btn btn-sm">Buscar</button>
    </form>
  </div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr>
        <th>RUC</th><th>Razón social</th><th>Nombre corto</th><th>Moneda</th><th>Valorización</th>
        <th class="num">Usuarios</th><th class="num">Productos</th><th>Estado</th><th>Acciones</th>
      </tr></thead>
      <tbody>
      <?php foreach ($empresas as $em): ?>
        <?php $esActual = (int) $em['id'] === Empresa::id(); ?>
        <tr>
          <td><?= e($em['ruc']) ?></td>
          <td><strong><?= e($em['razon_social']) ?></strong>
              <?php if ($esActual): ?><span class="badge badge-ok">activa</span><?php endif; ?></td>
          <td><?= e($em['nombre_corto']) ?></td>
          <td><?= e($em['simbolo']) ?> <?= e($em['moneda']) ?></td>
          <td>
            <span class="badge"><?= e($em['metodo_valorizacion']) ?></span>
            <?php if ($em['metodo_valorizacion'] === 'PROMEDIO'): ?>
              <small style="color:var(--suave)"><?= $em['ambito_costo'] === 'ALMACEN' ? 'por almacén' : 'global' ?></small>
            <?php endif; ?>
          </td>
          <td class="num"><?= (int) $em['usuarios'] ?></td>
          <td class="num"><?= (int) $em['productos'] ?></td>
          <td><span class="badge <?= (int) $em['estado'] === 1 ? 'badge-ok' : 'badge-error' ?>"><?= (int) $em['estado'] === 1 ? 'Activa' : 'Inactiva' ?></span></td>
          <td>
            <div class="acciones">
              <?php if (!$esActual && (int) $em['estado'] === 1): ?>
                <a class="btn btn-sm" href="<?= url('empresas.php?a=cambiar&id=' . $em['id']) ?>">Entrar</a>
              <?php endif; ?>
              <a class="btn btn-sm btn-gris" href="<?= url('empresas.php?id=' . $em['id']) ?>">Editar</a>
              <?php if (!$esActual && (int) $em['estado'] === 1): ?>
                <a class="btn btn-sm btn-rojo" href="<?= url('empresas.php?a=desactivar&id=' . $em['id']) ?>"
                   data-confirmar="¿Desactivar <?= e($em['razon_social']) ?>? Sus datos se conservan.">Desactivar</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  // Explica en una línea qué implica el método elegido y oculta el ámbito
  // cuando no aplica (PEPS y UEPS siempre trabajan por almacén).
  var sel    = document.getElementById('metodoVal');
  var ambito = document.getElementById('campoAmbito');
  var ayuda  = document.getElementById('ayudaMetodo');
  if (!sel) return;

  var textos = {
    PROMEDIO: 'Cada compra recalcula el costo unitario. Es el más simple de explicar y el más usado; suaviza las variaciones de precio.',
    PEPS: 'Las salidas se valorizan con el costo de las compras más antiguas. Con precios al alza deja un costo de ventas menor y existencias valorizadas más altas.',
    UEPS: 'Las salidas se valorizan con el costo de las compras más recientes. Con precios al alza eleva el costo de ventas y deja existencias más baratas. Ojo: no es aceptado por las normas contables NIIF.'
  };

  function refrescar() {
    ayuda.textContent = textos[sel.value] || '';
    ambito.style.display = sel.value === 'PROMEDIO' ? '' : 'none';
  }
  sel.addEventListener('change', refrescar);
  refrescar();
})();
</script>
