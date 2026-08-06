<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Salidas registradas</h2>
    <div class="acciones">
      <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaSalidas','salidas')">Exportar CSV</button>
      <?php if (Auth::puede('salidas.registrar')): ?>
        <a class="btn btn-sm" href="<?= url('salidas.php?a=form') ?>">+ Nueva salida</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="tarjeta-cuerpo">
    <form class="filtros" method="get">
      <div class="campo"><label>Desde</label><input type="date" name="desde" value="<?= e($filtros['desde']) ?>"></div>
      <div class="campo"><label>Hasta</label><input type="date" name="hasta" value="<?= e($filtros['hasta']) ?>"></div>
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
        <label>Motivo</label>
        <select name="motivo">
          <option value="">Todos</option>
          <?php foreach (Salida::MOTIVOS as $m): ?>
            <option <?= $filtros['motivo'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo"><label>Buscar</label><input type="text" name="q" value="<?= e($filtros['q']) ?>" placeholder="Serie o destino"></div>
      <button class="btn">Filtrar</button>
      <a class="btn btn-gris" href="<?= url('salidas.php') ?>">Limpiar</a>
    </form>
  </div>

  <div class="tabla-scroll">
    <?php if (!$salidas): ?>
      <p class="vacio">No hay salidas registradas.</p>
    <?php else: ?>
    <table class="tabla" id="tablaSalidas">
      <thead><tr>
        <th>Serie</th><th>Fecha</th><th>Almacén</th><th>Motivo</th><th>Destino</th>
        <th class="num">Ítems</th><th class="num">Valor</th><th>Usuario</th><th class="no-export"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($salidas as $s): ?>
        <tr>
          <td><strong><?= e($s['serie_numero']) ?></strong></td>
          <td><?= Vista::fecha($s['fecha']) ?></td>
          <td><?= e($s['almacen']) ?></td>
          <td><span class="badge"><?= e($s['motivo']) ?></span></td>
          <td><?= e($s['destino'] ?? '—') ?></td>
          <td class="num"><?= (int) $s['items'] ?></td>
          <td class="num"><?= Vista::num($s['total']) ?></td>
          <td><?= e($s['usuario']) ?></td>
          <td class="no-export"><a class="btn btn-sm btn-gris" href="<?= url('salidas.php?a=ver&id=' . $s['id']) ?>">Ver</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
