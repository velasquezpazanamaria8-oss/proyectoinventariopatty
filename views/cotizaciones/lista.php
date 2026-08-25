<?php
$colores = ['BORRADOR' => '', 'ENVIADA' => 'badge-warn', 'ACEPTADA' => 'badge-ok',
            'RECHAZADA' => 'badge-error', 'ANULADA' => 'badge-error'];
$simbolo = Empresa::simbolo();
?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Cotizaciones (<?= count($cotizaciones) ?>)</h2>
    <div class="acciones">
      <a class="btn btn-sm btn-gris" href="<?= url('cotizacion_diseno.php') ?>">Diseño</a>
      <a class="btn" href="<?= url('cotizaciones.php?a=form') ?>">Nueva cotización</a>
    </div>
  </div>

  <div class="tarjeta-cuerpo">
    <form method="get" class="filtros">
      <div class="campo">
        <label>Estado</label>
        <select name="estado">
          <option value="">Todos</option>
          <?php foreach (Cotizacion::ESTADOS as $v => $t): ?>
            <option value="<?= $v ?>" <?= $filtros['estado'] === $v ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Cliente</label>
        <select name="cliente_id">
          <option value="">Todos</option>
          <?php foreach ($clientes as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (string) $filtros['cliente_id'] === (string) $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo"><label>Desde</label><input type="date" name="desde" value="<?= e($filtros['desde']) ?>"></div>
      <div class="campo"><label>Hasta</label><input type="date" name="hasta" value="<?= e($filtros['hasta']) ?>"></div>
      <div class="campo" style="min-width:200px">
        <label>Buscar</label>
        <input type="text" name="q" value="<?= e($filtros['q']) ?>" placeholder="Cliente, RUC o referencia">
      </div>
      <div class="acciones">
        <button class="btn btn-sm">Filtrar</button>
        <a class="btn btn-sm btn-gris" href="<?= url('cotizaciones.php') ?>">Limpiar</a>
      </div>
    </form>
  </div>

  <div class="tabla-scroll">
    <?php if (!$cotizaciones): ?>
      <p class="vacio">Todavía no hay cotizaciones.
        <a href="<?= url('cotizaciones.php?a=form') ?>">Cree la primera</a>.</p>
    <?php else: ?>
    <table class="tabla" id="tablaCotizaciones">
      <thead><tr>
        <th>Número</th><th>Fecha</th><th>Cliente</th><th>Referencia</th>
        <th class="num">Líneas</th><th class="num">Total</th><th>Estado</th>
        <th class="no-export">Acciones</th>
      </tr></thead>
      <tbody>
      <?php foreach ($cotizaciones as $c): ?>
        <tr>
          <td><strong><?= e(CotizacionConfig::formatoNumero($cfg, (int) $c['numero'])) ?></strong></td>
          <td><?= Vista::fecha($c['fecha']) ?>
            <?php if (!empty($c['valida_hasta']) && $c['estado'] === 'ENVIADA'
                      && $c['valida_hasta'] < date('Y-m-d')): ?>
              <br><small style="color:var(--error)">venció el <?= Vista::fecha($c['valida_hasta']) ?></small>
            <?php endif; ?>
          </td>
          <td style="white-space:normal;max-width:250px"><?= e($c['cliente_nombre']) ?>
            <?php if ($c['cliente_ruc']): ?>
              <br><small style="color:var(--suave)"><?= e($c['cliente_ruc']) ?></small>
            <?php endif; ?>
          </td>
          <td><?= e($c['referencia'] ?? '—') ?></td>
          <td class="num"><?= (int) $c['lineas'] ?></td>
          <td class="num"><?= e($simbolo) ?> <?= Vista::num($c['total'], 2) ?></td>
          <td><span class="badge <?= $colores[$c['estado']] ?>"><?= e(Cotizacion::ESTADOS[$c['estado']]) ?></span></td>
          <td class="no-export">
            <div class="acciones">
              <a class="btn btn-sm btn-gris" href="<?= url('cotizaciones.php?a=ver&id=' . $c['id']) ?>">Ver</a>
              <a class="btn btn-sm btn-rojo" target="_blank" rel="noopener"
                 href="<?= url('cotizacion_pdf.php?id=' . $c['id']) ?>">PDF</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
