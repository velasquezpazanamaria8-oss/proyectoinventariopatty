<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Stock actual — físico, reservado y disponible</h2>
    <?php $qsS = http_build_query(array_filter(['r' => 'stock_actual'] + $filtros)); ?>
    <div class="acciones">
      <a class="btn btn-sm btn-rojo" href="<?= url('exportar.php?f=pdf&' . $qsS) ?>">PDF</a>
      <a class="btn btn-sm btn-verde" href="<?= url('exportar.php?f=xlsx&' . $qsS) ?>">Excel</a>
      <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaStock','stock_actual')">CSV</button>
      <button class="btn btn-sm btn-gris" onclick="window.print()">Imprimir</button>
    </div>
  </div>

  <div class="tarjeta-cuerpo">
    <form class="filtros" method="get">
      <div class="campo"><label>Buscar</label><input type="text" name="q" value="<?= e($filtros['q']) ?>" placeholder="Código o descripción"></div>
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
        <label>Categoría</label>
        <select name="categoria_id">
          <option value="">Todas</option>
          <?php foreach ($categorias as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (string) $filtros['categoria_id'] === (string) $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn">Filtrar</button>
      <a class="btn btn-gris" href="<?= url('inventario.php') ?>">Limpiar</a>
    </form>
  </div>

  <div class="tabla-scroll">
    <?php if (!$filas): ?>
      <p class="vacio">No hay productos con esos criterios.</p>
    <?php else: ?>
    <table class="tabla" id="tablaStock">
      <thead><tr>
        <th>Código</th><th>Producto</th><th>Categoría</th><th>Und</th>
        <th class="num">Físico</th><th class="num">Reservado</th><th class="num">Disponible</th>
        <th class="num">Mínimo</th><th class="num">C. promedio</th><th class="num">Valorizado</th><th>Estado</th>
      </tr></thead>
      <tbody>
      <?php foreach ($filas as $f):
        $disp = (float) $f['disponible']; $min = (float) $f['stock_minimo']; ?>
        <tr>
          <td><?= e($f['codigo']) ?></td>
          <td><?= e($f['descripcion']) ?></td>
          <td><?= e($f['categoria'] ?? '—') ?></td>
          <td><?= e($f['unidad']) ?></td>
          <td class="num"><?= Vista::num($f['fisico']) ?></td>
          <td class="num"><?= Vista::num($f['reservado']) ?></td>
          <td class="num"><strong><?= Vista::num($f['disponible']) ?></strong></td>
          <td class="num"><?= Vista::num($f['stock_minimo']) ?></td>
          <td class="num"><?= Vista::num($f['costo_promedio'], 4) ?></td>
          <td class="num"><?= Vista::num($f['valor']) ?></td>
          <td>
            <?php if ($disp <= 0): ?><span class="badge badge-error">AGOTADO</span>
            <?php elseif ($disp <= $min): ?><span class="badge badge-warn">STOCK MÍNIMO</span>
            <?php else: ?><span class="badge badge-ok">NORMAL</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if (Auth::puede('reportes.valorizado') || Auth::rol() === 'ADMINISTRADOR'): ?>
      <tfoot><tr><th colspan="9" class="num">TOTAL VALORIZADO</th><th class="num"><?= Vista::num($total) ?></th><th></th></tr></tfoot>
      <?php endif; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
