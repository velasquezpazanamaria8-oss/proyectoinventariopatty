<div class="kpis">
  <div class="kpi">
    <div class="etiqueta">Productos activos</div>
    <div class="valor"><?= (int) $resumen['productos'] ?></div>
  </div>
  <div class="kpi alerta">
    <div class="etiqueta">En stock mínimo</div>
    <div class="valor"><?= (int) $resumen['stock_minimo'] ?></div>
  </div>
  <div class="kpi peligro">
    <div class="etiqueta">Agotados</div>
    <div class="valor"><?= (int) $resumen['agotados'] ?></div>
  </div>
  <div class="kpi exito">
    <div class="etiqueta">Entradas hoy</div>
    <div class="valor"><?= (int) $resumen['entradas_hoy'] ?></div>
  </div>
  <div class="kpi">
    <div class="etiqueta">Salidas hoy</div>
    <div class="valor"><?= (int) $resumen['salidas_hoy'] ?></div>
  </div>
  <?php if (Auth::puede('reportes.valorizado') || Auth::rol() === 'ADMINISTRADOR'): ?>
  <div class="kpi">
    <div class="etiqueta">Inventario valorizado</div>
    <div class="valor">S/ <?= Vista::num($resumen['valor_total']) ?></div>
  </div>
  <?php endif; ?>
</div>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Alertas de stock mínimo</h2>
    <a class="btn btn-sm btn-gris" href="<?= url('reportes.php?r=stock_minimo') ?>">Ver todo</a>
  </div>
  <div class="tabla-scroll">
    <?php if (!$alertas): ?>
      <p class="vacio">Ningún producto por debajo del stock mínimo.</p>
    <?php else: ?>
    <table class="tabla">
      <thead><tr>
        <th>Código</th><th>Producto</th><th class="num">Stock</th>
        <th class="num">Mínimo</th><th>Estado</th>
      </tr></thead>
      <tbody>
      <?php foreach ($alertas as $a): ?>
        <tr>
          <td><?= e($a['codigo']) ?></td>
          <td><?= e($a['descripcion']) ?></td>
          <td class="num"><?= Vista::num($a['stock_actual']) ?> <?= e($a['unidad']) ?></td>
          <td class="num"><?= Vista::num($a['stock_minimo']) ?></td>
          <td><?php if ((float) $a['stock_actual'] <= 0): ?>
                <span class="badge badge-error">AGOTADO</span>
              <?php else: ?>
                <span class="badge badge-warn">STOCK MÍNIMO</span>
              <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Últimos movimientos</h2>
    <?php if (Auth::puede('kardex.ver')): ?>
      <a class="btn btn-sm btn-gris" href="<?= url('kardex.php') ?>">Ver kardex</a>
    <?php endif; ?>
  </div>
  <div class="tabla-scroll">
    <?php if (!$ultimos): ?>
      <p class="vacio">Aún no se registran movimientos.</p>
    <?php else: ?>
    <table class="tabla">
      <thead><tr>
        <th>Fecha</th><th>Documento</th><th>Tipo</th><th>Producto</th>
        <th class="num">Cantidad</th><th class="num">Saldo</th><th>Usuario</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ultimos as $k): ?>
        <tr>
          <td><?= Vista::fecha($k['fecha'], true) ?></td>
          <td><?= e($k['documento']) ?></td>
          <td><span class="badge <?= str_starts_with($k['tipo'], 'SALIDA') || $k['tipo'] === 'AJUSTE_NEG' ? 'badge-error' : 'badge-ok' ?>"><?= e($k['tipo']) ?></span></td>
          <td><?= e($k['descripcion']) ?></td>
          <td class="num"><?= Vista::num($k['cantidad']) ?></td>
          <td class="num"><?= Vista::num($k['saldo_cantidad']) ?></td>
          <td><?= e($k['usuario']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
