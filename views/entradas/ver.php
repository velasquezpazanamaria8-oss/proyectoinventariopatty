<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Entrada <?= e($entrada['serie_numero']) ?></h2>
    <div class="acciones">
      <button class="btn btn-sm btn-gris" onclick="window.print()">Imprimir</button>
      <a class="btn btn-sm btn-gris" href="<?= url('entradas.php') ?>">Volver</a>
    </div>
  </div>
  <div class="tarjeta-cuerpo">
    <div class="form-grid">
      <div><strong>Fecha:</strong> <?= Vista::fecha($entrada['fecha']) ?></div>
      <div><strong>Almacén:</strong> <?= e($entrada['almacen']) ?></div>
      <div><strong>Proveedor:</strong> <?= e($entrada['proveedor'] ?? '—') ?></div>
      <div><strong>Documento:</strong> <?= e(trim(($entrada['tipo_documento'] ?? '') . ' ' . ($entrada['nro_documento'] ?? ''))) ?: '—' ?></div>
      <div><strong>Registrado por:</strong> <?= e($entrada['usuario_nombre']) ?></div>
      <div><strong>Estado:</strong> <span class="badge badge-ok"><?= e($entrada['estado']) ?></span></div>
      <div style="grid-column:span 2"><strong>Observación:</strong> <?= e($entrada['observacion'] ?? '—') ?></div>
    </div>
  </div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr>
        <th>Código</th><th>Producto</th><th>Und</th>
        <th class="num">Cantidad</th><th class="num">Costo unit.</th><th class="num">Subtotal</th>
      </tr></thead>
      <tbody>
      <?php foreach ($entrada['detalle'] as $d): ?>
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
      <tfoot><tr><th colspan="5" class="num">TOTAL</th><th class="num"><?= Vista::num($entrada['total']) ?></th></tr></tfoot>
    </table>
  </div>
</div>
