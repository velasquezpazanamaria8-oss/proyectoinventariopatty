<?php $puede = Auth::puede('inventario.gestionar'); ?>

<?php if ($puede): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Abrir nuevo conteo físico</h2></div>
  <form method="post" class="tarjeta-cuerpo">
    <?= Csrf::campo() ?>
    <input type="hidden" name="op" value="abrir">
    <div class="alerta alerta-info">
      Al abrir el conteo se congela el stock que el sistema tiene en ese momento para cada producto.
      Luego se registra lo contado físicamente y, al cerrar, el sistema genera automáticamente
      los ajustes necesarios para que ambos coincidan. Cada ajuste queda en el kardex con su motivo.
    </div>
    <div class="form-grid">
      <div class="campo">
        <label>Fecha *</label>
        <input type="date" name="fecha" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="campo">
        <label>Almacén *</label>
        <select name="almacen_id" required>
          <?php foreach ($almacenes as $id => $nom): ?><option value="<?= $id ?>"><?= e($nom) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="campo" style="grid-column:span 2">
        <label>Observación</label>
        <input type="text" name="observacion" maxlength="255" placeholder="Ej.: inventario semestral, conteo por auditoría...">
      </div>
      <div class="campo">
        <label>Alcance</label>
        <label style="font-weight:400;display:flex;gap:7px;align-items:center;margin-top:4px">
          <input type="checkbox" name="solo_con_stock" value="1" style="width:auto">
          Sólo productos con stock distinto de cero
        </label>
      </div>
    </div>
    <div class="acciones" style="margin-top:12px">
      <button class="btn btn-verde" type="submit">Abrir conteo</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Conteos registrados (<?= count($conteos) ?>)</h2>
  </div>
  <div class="tarjeta-cuerpo">
    <form class="filtros" method="get">
      <div class="campo">
        <label>Almacén</label>
        <select name="almacen_id">
          <option value="">Todos</option>
          <?php foreach ($almacenes as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (string) $filtros['almacen_id'] === (string) $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Estado</label>
        <select name="estado">
          <option value="">Todos</option>
          <option value="ABIERTO" <?= $filtros['estado'] === 'ABIERTO' ? 'selected' : '' ?>>Abiertos</option>
          <option value="CERRADO" <?= $filtros['estado'] === 'CERRADO' ? 'selected' : '' ?>>Cerrados</option>
        </select>
      </div>
      <button class="btn">Filtrar</button>
      <a class="btn btn-gris" href="<?= url('inventario_fisico.php') ?>">Limpiar</a>
    </form>
  </div>

  <div class="tabla-scroll">
    <?php if (!$conteos): ?>
      <p class="vacio">Aún no se ha realizado ningún inventario físico.</p>
    <?php else: ?>
    <table class="tabla">
      <thead><tr>
        <th>Código</th><th>Fecha</th><th>Almacén</th>
        <th class="num">Productos</th><th class="num">Contados</th><th class="num">Con diferencia</th>
        <th>Estado</th><th>Responsable</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($conteos as $c): ?>
        <tr>
          <td><strong><?= e($c['codigo']) ?></strong></td>
          <td><?= Vista::fecha($c['fecha']) ?></td>
          <td><?= e($c['almacen']) ?></td>
          <td class="num"><?= (int) $c['items'] ?></td>
          <td class="num"><?= (int) $c['contados'] ?></td>
          <td class="num"><?= (int) $c['con_diferencia'] ?></td>
          <td><span class="badge <?= $c['estado'] === 'ABIERTO' ? 'badge-warn' : 'badge-ok' ?>"><?= e($c['estado']) ?></span></td>
          <td><?= e($c['usuario']) ?></td>
          <td><a class="btn btn-sm <?= $c['estado'] === 'ABIERTO' ? '' : 'btn-gris' ?>"
                 href="<?= url('inventario_fisico.php?a=ver&id=' . $c['id']) ?>">
                <?= $c['estado'] === 'ABIERTO' ? 'Continuar' : 'Ver' ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
