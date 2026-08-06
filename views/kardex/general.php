<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Kardex general</h2>
    <?php $qsK = http_build_query(array_filter(['r' => 'kardex_general'] + $filtros)); ?>
    <div class="acciones">
      <a class="btn btn-sm btn-rojo" href="<?= url('exportar.php?f=pdf&' . $qsK) ?>">PDF</a>
      <a class="btn btn-sm btn-verde" href="<?= url('exportar.php?f=xlsx&' . $qsK) ?>">Excel</a>
      <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaKardexGeneral','kardex_general')">CSV</button>
      <button class="btn btn-sm btn-gris" onclick="window.print()">Imprimir</button>
    </div>
  </div>

  <div class="tarjeta-cuerpo">
    <p style="color:var(--suave);margin-top:0">
      Para ver el historial detallado de un producto, use el botón <em>Kardex</em> desde el listado de productos.
    </p>
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
        <label>Tipo</label>
        <select name="tipo">
          <option value="">Todos</option>
          <?php foreach (['ENTRADA','SALIDA','AJUSTE_POS','AJUSTE_NEG','INV_INICIAL'] as $t): ?>
            <option <?= $filtros['tipo'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo"><label>Desde</label><input type="date" name="desde" value="<?= e($filtros['desde']) ?>"></div>
      <div class="campo"><label>Hasta</label><input type="date" name="hasta" value="<?= e($filtros['hasta']) ?>"></div>
      <button class="btn">Filtrar</button>
      <a class="btn btn-gris" href="<?= url('kardex.php') ?>">Limpiar</a>
    </form>
  </div>

  <div class="tabla-scroll">
    <?php if (!$movimientos): ?>
      <p class="vacio">No hay movimientos con esos criterios.</p>
    <?php else: ?>
    <table class="tabla" id="tablaKardexGeneral">
      <thead><tr>
        <th>Fecha</th><th>Documento</th><th>Tipo</th><th>Código</th><th>Producto</th>
        <th>Almacén</th><th class="num">Cantidad</th><th class="num">Saldo</th>
        <th class="num">Costo unit.</th><th>Usuario</th><th class="no-export"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($movimientos as $m):
        $esIngreso = in_array($m['tipo'], ['ENTRADA', 'AJUSTE_POS', 'INV_INICIAL'], true); ?>
        <tr>
          <td><?= Vista::fecha($m['fecha'], true) ?></td>
          <td><?= e($m['documento']) ?></td>
          <td><span class="badge <?= $esIngreso ? 'badge-ok' : 'badge-error' ?>"><?= e($m['tipo']) ?></span></td>
          <td><?= e($m['codigo']) ?></td>
          <td><?= e($m['descripcion']) ?></td>
          <td><?= e($m['almacen']) ?></td>
          <td class="num"><?= ($esIngreso ? '+' : '-') . Vista::num($m['cantidad']) ?></td>
          <td class="num"><?= Vista::num($m['saldo_cantidad']) ?></td>
          <td class="num"><?= Vista::num($m['costo_unitario'], 4) ?></td>
          <td><?= e($m['usuario']) ?></td>
          <td class="no-export">
            <a class="btn btn-sm btn-gris" href="<?= url('kardex.php?producto_id=' . $m['producto_id']) ?>">Ver kardex</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
