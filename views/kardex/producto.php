<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Kardex: <?= e($producto['codigo']) ?> — <?= e($producto['descripcion']) ?></h2>
    <?php $qsK = http_build_query(array_filter([
        'r' => 'kardex_producto', 'producto_id' => $producto['id'],
        'almacen_id' => $filtros['almacen_id'], 'desde' => $filtros['desde'], 'hasta' => $filtros['hasta'],
    ])); ?>
    <div class="acciones">
      <a class="btn btn-sm btn-rojo" href="<?= url('exportar.php?f=pdf&' . $qsK) ?>">PDF</a>
      <a class="btn btn-sm btn-verde" href="<?= url('exportar.php?f=xlsx&' . $qsK) ?>">Excel</a>
      <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaKardex','kardex_<?= e($producto['codigo']) ?>')">CSV</button>
      <button class="btn btn-sm btn-gris" onclick="window.print()">Imprimir</button>
      <a class="btn btn-sm btn-gris" href="<?= url('kardex.php') ?>">Kardex general</a>
    </div>
  </div>

  <div class="tarjeta-cuerpo">
    <div class="kpis" style="margin-bottom:14px">
      <div class="kpi"><div class="etiqueta">Stock actual</div>
        <div class="valor"><?= Vista::num($producto['stock_actual']) ?> <small><?= e($producto['unidad']) ?></small></div></div>
      <div class="kpi"><div class="etiqueta">Stock mínimo</div>
        <div class="valor"><?= Vista::num($producto['stock_minimo']) ?></div></div>
      <div class="kpi"><div class="etiqueta">Costo promedio</div>
        <div class="valor"><?= Vista::num($producto['costo_promedio'], 4) ?></div></div>
      <div class="kpi"><div class="etiqueta">Valorizado</div>
        <div class="valor"><?= Vista::num((float) $producto['stock_actual'] * (float) $producto['costo_promedio']) ?></div></div>
    </div>

    <p style="margin:0 0 12px">
      <span class="badge"><?= e(Valorizacion::etiqueta()) ?></span>
      <small style="color:var(--suave)">— método de valorización de esta empresa</small>
    </p>

    <form class="filtros" method="get">
      <input type="hidden" name="producto_id" value="<?= (int) $producto['id'] ?>">
      <div class="campo">
        <label>Almacén</label>
        <select name="almacen_id">
          <option value="">Todos</option>
          <?php foreach ($almacenes as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (string) $filtros['almacen_id'] === (string) $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo"><label>Desde</label><input type="date" name="desde" value="<?= e($filtros['desde']) ?>"></div>
      <div class="campo"><label>Hasta</label><input type="date" name="hasta" value="<?= e($filtros['hasta']) ?>"></div>
      <button class="btn">Filtrar</button>
      <a class="btn btn-gris" href="<?= url('kardex.php?producto_id=' . $producto['id']) ?>">Limpiar</a>
    </form>
  </div>

  <?php if (Valorizacion::usaCapas()):
          $capas = Valorizacion::capasVigentes((int) $producto['id'],
                     $filtros['almacen_id'] ? (int) $filtros['almacen_id'] : null); ?>
    <div class="tarjeta-cuerpo" style="border-top:1px solid var(--linea)">
      <h3 style="font-size:14px">Capas de costo pendientes</h3>
      <p style="color:var(--suave);font-size:12.5px;margin-top:0">
        Con <?= e(Valorizacion::metodo()) ?> las salidas consumen estas capas
        <?= Valorizacion::metodo() === Valorizacion::PEPS ? 'de arriba hacia abajo (la más antigua primero)'
                                                         : 'de abajo hacia arriba (la más reciente primero)' ?>.
      </p>
      <?php if (!$capas): ?>
        <p class="vacio" style="padding:12px">Sin existencias pendientes.</p>
      <?php else: ?>
        <div class="tabla-scroll">
          <table class="tabla">
            <thead><tr>
              <th>Ingreso</th><th>Documento</th><th>Almacén</th>
              <th class="num">Cantidad original</th><th class="num">Queda</th>
              <th class="num">Costo unit.</th><th class="num">Valor pendiente</th>
            </tr></thead>
            <tbody>
            <?php foreach ($capas as $c): ?>
              <tr>
                <td><?= Vista::fecha($c['fecha'], true) ?></td>
                <td><?= e($c['documento'] ?? '—') ?></td>
                <td><?= e($c['almacen']) ?></td>
                <td class="num"><?= Vista::num($c['cantidad_ini']) ?></td>
                <td class="num"><strong><?= Vista::num($c['cantidad_resta']) ?></strong></td>
                <td class="num"><?= Vista::num($c['costo_unitario'], 4) ?></td>
                <td class="num"><?= Vista::num((float) $c['cantidad_resta'] * (float) $c['costo_unitario']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="tabla-scroll">
    <?php if (!$movimientos): ?>
      <p class="vacio">Este producto no tiene movimientos registrados.</p>
    <?php else: ?>
    <table class="tabla" id="tablaKardex">
      <thead><tr>
        <th>Fecha</th><th>Documento</th><th>Tipo</th><th>Almacén</th><th>Motivo</th>
        <th class="num">Entrada</th><th class="num">Salida</th>
        <th class="num">Saldo</th><th class="num">Costo unit.</th>
        <th class="num">C. promedio</th><th class="num">Valor saldo</th><th>Usuario</th>
      </tr></thead>
      <tbody>
      <?php foreach ($movimientos as $m):
        $esIngreso = in_array($m['tipo'], ['ENTRADA', 'AJUSTE_POS', 'INV_INICIAL'], true); ?>
        <tr>
          <td><?= Vista::fecha($m['fecha'], true) ?></td>
          <td><?= e($m['documento']) ?></td>
          <td><span class="badge <?= $esIngreso ? 'badge-ok' : 'badge-error' ?>"><?= e($m['tipo']) ?></span></td>
          <td><?= e($m['almacen']) ?></td>
          <td><?= e($m['motivo'] ?? '—') ?></td>
          <td class="num"><?= $esIngreso ? Vista::num($m['cantidad']) : '' ?></td>
          <td class="num"><?= $esIngreso ? '' : Vista::num($m['cantidad']) ?></td>
          <td class="num"><strong><?= Vista::num($m['saldo_cantidad']) ?></strong></td>
          <td class="num"><?= Vista::num($m['costo_unitario'], 4) ?></td>
          <td class="num"><?= Vista::num($m['saldo_costo'], 4) ?></td>
          <td class="num"><?= Vista::num($m['saldo_valor']) ?></td>
          <td><?= e($m['usuario']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
