<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Salida <?= e($salida['serie_numero']) ?></h2>
    <div class="acciones">
      <button class="btn btn-sm btn-gris" onclick="window.print()">Imprimir</button>
      <a class="btn btn-sm btn-gris" href="<?= url('salidas.php') ?>">Volver</a>
    </div>
  </div>
  <div class="tarjeta-cuerpo">
    <div class="form-grid">
      <div><strong>Fecha:</strong> <?= Vista::fecha($salida['fecha']) ?></div>
      <div><strong>Almacén:</strong> <?= e($salida['almacen']) ?></div>
      <div><strong>Motivo:</strong> <?= e($salida['motivo']) ?></div>
      <div><strong>Destino:</strong> <?= e($salida['destino'] ?? '—') ?></div>
      <div><strong>Registrado por:</strong> <?= e($salida['usuario_nombre']) ?></div>
      <div><strong>Estado:</strong> <span class="badge badge-ok"><?= e($salida['estado']) ?></span></div>
      <div style="grid-column:span 2"><strong>Observación:</strong> <?= e($salida['observacion'] ?? '—') ?></div>
    </div>
  </div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr>
        <th>Código</th><th>Producto</th><th>Und</th>
        <th class="num">Cantidad</th><th class="num">Costo unit.</th><th class="num">Valor</th>
      </tr></thead>
      <tbody>
      <?php foreach ($salida['detalle'] as $d): ?>
        <tr>
          <td><?= e($d['codigo']) ?></td>
          <td><?= e($d['descripcion']) ?></td>
          <td><?= e($d['unidad']) ?></td>
          <td class="num"><?= Vista::num($d['cantidad']) ?></td>
          <td class="num"><?= Vista::num($d['costo_unitario'], 4) ?></td>
          <td class="num"><?= Vista::num($d['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th colspan="5" class="num">TOTAL VALORIZADO</th><th class="num"><?= Vista::num($salida['total']) ?></th></tr></tfoot>
    </table>
  </div>
</div>
