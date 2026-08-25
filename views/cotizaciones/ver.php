<?php
$colores = ['BORRADOR' => '', 'ENVIADA' => 'badge-warn', 'ACEPTADA' => 'badge-ok',
            'RECHAZADA' => 'badge-error', 'ANULADA' => 'badge-error'];
$simbolo = Empresa::simbolo();
$editable = Cotizacion::editable($cot);
$num = CotizacionConfig::formatoNumero($cfg, (int) $cot['numero']);

// Qué se puede hacer desde cada estado. Anular está siempre disponible salvo
// que ya generó una salida de inventario.
$siguientes = match ($cot['estado']) {
    'BORRADOR'  => ['ENVIADA' => 'Marcar como enviada'],
    'ENVIADA'   => ['ACEPTADA' => 'El cliente la aceptó', 'RECHAZADA' => 'El cliente la rechazó'],
    'RECHAZADA' => ['ENVIADA' => 'Volver a enviarla'],
    default     => [],
};
?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Cotización N° <?= e($num) ?>
      <span class="badge <?= $colores[$cot['estado']] ?>"><?= e(Cotizacion::ESTADOS[$cot['estado']]) ?></span>
    </h2>
    <div class="acciones">
      <a class="btn btn-sm btn-gris" href="<?= url('cotizaciones.php') ?>">Volver</a>
      <?php if ($editable): ?>
        <a class="btn btn-sm" href="<?= url('cotizaciones.php?a=form&id=' . $cot['id']) ?>">Editar</a>
      <?php endif; ?>
      <a class="btn btn-sm btn-rojo" target="_blank" rel="noopener"
         href="<?= url('cotizacion_pdf.php?id=' . $cot['id']) ?>">Ver PDF</a>
      <a class="btn btn-sm btn-gris"
         href="<?= url('cotizacion_pdf.php?bajar=1&id=' . $cot['id']) ?>">Descargar</a>
    </div>
  </div>

  <div class="tarjeta-cuerpo">
    <div class="form-grid">
      <div class="campo"><label>Cliente</label>
        <strong><?= e($cot['cliente_nombre']) ?></strong>
        <?php if ($cot['cliente_ruc']): ?><small style="color:var(--suave)"><?= e($cot['cliente_ruc']) ?></small><?php endif; ?>
      </div>
      <div class="campo"><label>Fecha</label><?= Vista::fecha($cot['fecha']) ?></div>
      <div class="campo"><label>Válida hasta</label>
        <?= $cot['valida_hasta'] ? Vista::fecha($cot['valida_hasta']) : '—' ?></div>
      <div class="campo"><label><?= e($cfg['etiqueta_ref'] ?: 'Referencia') ?></label>
        <?= e($cot['referencia'] ?? '—') ?></div>
    </div>
    <?php if (!empty($cot['observacion'])): ?>
      <p style="color:var(--suave);margin:10px 0 0"><?= e($cot['observacion']) ?></p>
    <?php endif; ?>
  </div>

  <div class="tabla-scroll">
    <table class="tabla" id="tablaDet">
      <thead><tr>
        <th>Unidad</th><th class="num">Cantidad</th><th>Descripción</th>
        <th class="num">Precio unit.</th><th class="num">Importe</th>
      </tr></thead>
      <tbody>
      <?php foreach ($cot['detalle'] as $d): ?>
        <tr>
          <td><?= e($d['unidad'] ?? '—') ?></td>
          <td class="num"><?= Vista::num($d['cantidad']) ?></td>
          <td style="white-space:normal"><?= e($d['descripcion']) ?>
            <?php if ($d['producto_codigo']): ?>
              <br><small style="color:var(--suave)">catálogo: <?= e($d['producto_codigo']) ?></small>
            <?php else: ?>
              <br><small style="color:var(--suave)">línea libre</small>
            <?php endif; ?>
          </td>
          <td class="num"><?= Vista::num($d['precio_unitario'], 2) ?></td>
          <td class="num"><?= Vista::num($d['importe'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="tarjeta-cuerpo">
    <div style="max-width:340px;margin-left:auto">
      <div class="campo" style="flex-direction:row;justify-content:space-between">
        <span style="color:var(--suave)">Subtotal</span>
        <span><?= e($simbolo) ?> <?= Vista::num($cot['subtotal'], 2) ?></span></div>
      <div class="campo" style="flex-direction:row;justify-content:space-between">
        <span style="color:var(--suave)">IGV (18%)</span>
        <span><?= e($simbolo) ?> <?= Vista::num($cot['igv'], 2) ?></span></div>
      <div class="campo" style="flex-direction:row;justify-content:space-between;font-size:16px">
        <strong>TOTAL</strong>
        <strong><?= e($simbolo) ?> <?= Vista::num($cot['total'], 2) ?></strong></div>
      <p style="color:var(--suave);font-size:12px;text-align:right;margin:0">
        <?= $cot['incluye_igv'] ? 'Los precios incluyen IGV.' : 'Los precios no incluyen IGV.' ?>
      </p>
    </div>
  </div>

  <div class="tarjeta-cuerpo" style="border-top:1px solid var(--linea)">
    <div class="acciones">
      <?php foreach ($siguientes as $estado => $texto): ?>
        <form method="post" style="margin:0">
          <?= Csrf::campo() ?>
          <input type="hidden" name="op" value="estado">
          <input type="hidden" name="id" value="<?= (int) $cot['id'] ?>">
          <input type="hidden" name="estado" value="<?= e($estado) ?>">
          <button class="btn btn-sm <?= $estado === 'ACEPTADA' ? 'btn-verde' : 'btn-gris' ?>"
                  data-confirmar="<?= e($texto) ?>. ¿Continuar?"><?= e($texto) ?></button>
        </form>
      <?php endforeach; ?>

      <form method="post" style="margin:0">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="duplicar">
        <input type="hidden" name="id" value="<?= (int) $cot['id'] ?>">
        <button class="btn btn-sm btn-gris">Duplicar</button>
      </form>

      <?php if ($editable): ?>
        <form method="post" style="margin:0">
          <?= Csrf::campo() ?>
          <input type="hidden" name="op" value="eliminar">
          <input type="hidden" name="id" value="<?= (int) $cot['id'] ?>">
          <button class="btn btn-sm btn-rojo"
                  data-confirmar="Se eliminará el borrador N° <?= e($num) ?>. ¿Continuar?">Eliminar</button>
        </form>
      <?php elseif (empty($cot['salida_id']) && $cot['estado'] !== 'ANULADA'): ?>
        <form method="post" style="margin:0">
          <?= Csrf::campo() ?>
          <input type="hidden" name="op" value="estado">
          <input type="hidden" name="id" value="<?= (int) $cot['id'] ?>">
          <input type="hidden" name="estado" value="ANULADA">
          <button class="btn btn-sm btn-rojo"
                  data-confirmar="Anular la cotización N° <?= e($num) ?>. Se conserva para dejar rastro. ¿Continuar?">Anular</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (!$editable): ?>
      <p style="color:var(--suave);font-size:12.5px;margin:10px 0 0">
        Una cotización que ya salió al cliente no se modifica ni se borra: se anula, o se
        <strong>duplica</strong> para partir de ella y enviar una versión nueva.
      </p>
    <?php endif; ?>
  </div>
</div>
