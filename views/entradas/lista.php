<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Entradas registradas</h2>
    <div class="acciones">
      <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaEntradas','entradas')">Exportar CSV</button>
      <?php if (Auth::puede('entradas.registrar')): ?>
        <a class="btn btn-sm" href="<?= url('entradas.php?a=form') ?>">+ Nueva entrada</a>
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
      <div class="campo"><label>Documento</label><input type="text" name="q" value="<?= e($filtros['q']) ?>" placeholder="Serie o N° doc."></div>
      <button class="btn">Filtrar</button>
      <a class="btn btn-gris" href="<?= url('entradas.php') ?>">Limpiar</a>
    </form>
  </div>

  <div class="tabla-scroll">
    <?php if (!$entradas): ?>
      <p class="vacio">No hay entradas registradas.</p>
    <?php else: ?>
    <table class="tabla" id="tablaEntradas">
      <thead><tr>
        <th>Serie</th><th>Fecha</th><th>Almacén</th><th>Proveedor</th>
        <th>Documento</th><th class="num">Ítems</th><th class="num">Total</th>
        <th>Usuario</th><th class="no-export"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($entradas as $en): ?>
        <tr>
          <td><strong><?= e($en['serie_numero']) ?></strong></td>
          <td><?= Vista::fecha($en['fecha']) ?></td>
          <td><?= e($en['almacen']) ?></td>
          <td><?= e($en['proveedor'] ?? '—') ?></td>
          <td><?= e(trim(($en['tipo_documento'] ?? '') . ' ' . ($en['nro_documento'] ?? ''))) ?: '—' ?></td>
          <td class="num"><?= (int) $en['items'] ?></td>
          <td class="num"><?= Vista::num($en['total']) ?></td>
          <td><?= e($en['usuario']) ?></td>
          <td class="no-export"><a class="btn btn-sm btn-gris" href="<?= url('entradas.php?a=ver&id=' . $en['id']) ?>">Ver</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
