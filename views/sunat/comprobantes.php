<?php
/** Período por defecto para el desplegable: el filtrado, o el más reciente sincronizado. */
$periodoSel = $filtros['periodo'] ?: (array_key_first($sincronizados) ?: '');
$fmtPer = fn(string $p): string => substr($p, 4, 2) . '/' . substr($p, 0, 4);
?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Traer comprobantes del SIRE</h2>
    <a class="btn btn-sm btn-gris" href="<?= url('sunat.php') ?>">Credenciales</a>
  </div>

  <form method="post" class="tarjeta-cuerpo">
    <?= Csrf::campo() ?>
    <div class="alerta alerta-info">
      El SIRE dice <strong>qué comprobantes existen</strong> en el período y sus montos, pero
      <strong>no trae el detalle de productos</strong>: eso llega al descargar cada comprobante,
      en la siguiente fase. Esta pantalla <strong>no modifica el inventario</strong>.
    </div>

    <div class="filtros">
      <div class="campo">
        <label>Período *</label>
        <select name="periodo" required>
          <?php foreach ($periodosSunat as $per => $estado): ?>
            <option value="<?= e($per) ?>" <?= (string) $per === (string) $periodoSel ? 'selected' : '' ?>>
              <?= e($fmtPer($per)) ?> — <?= e($estado) ?>
              <?= isset($sincronizados[$per]) ? ' (ya traído)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-verde" type="submit">Traer del SIRE</button>
    </div>
    <p style="color:var(--suave);font-size:12.5px;margin-bottom:0">
      Traer un período ya sincronizado lo <strong>actualiza</strong>, no duplica.
      Cada período son unas cuantas llamadas a SUNAT: puede tardar algunos segundos.
    </p>
  </form>
</div>

<?php if ($sincronizacion): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Resultado de la sincronización — <?= e($fmtPer($sincronizacion['periodo'])) ?></h2></div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr>
        <th>Tipo</th><th class="num">Traídos</th><th class="num">Docs. según SUNAT</th>
        <th class="num">Base (detalle)</th><th class="num">Base (SUNAT)</th>
        <th class="num">IGV (detalle)</th><th class="num">IGV (SUNAT)</th><th>Contraste</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sincronizacion['tipos'] as $tipo => $r): ?>
        <tr>
          <td><strong><?= e(ucfirst($tipo)) ?></strong></td>
          <td class="num">
            <?= (int) $r['descargados'] ?>
            <?php if ($r['guardados'] !== $r['descargados']): ?>
              <span class="badge badge-error" title="Se guardaron <?= (int) $r['guardados'] ?>">
                guardados: <?= (int) $r['guardados'] ?></span>
            <?php endif; ?>
          </td>
          <td class="num"><?= (int) $r['sunat_docs'] ?></td>
          <td class="num"><?= Vista::num($r['suma_base']) ?></td>
          <td class="num"><?= Vista::num($r['sunat_base']) ?></td>
          <td class="num"><?= Vista::num($r['suma_igv']) ?></td>
          <td class="num"><?= Vista::num($r['sunat_igv']) ?></td>
          <td>
            <?php if ($r['vacio']): ?><span class="badge badge-warn">Período sin comprobantes</span>
            <?php elseif ($r['cuadra']): ?><span class="badge badge-ok">Cuadra</span>
            <?php else: ?><span class="badge badge-error">No cuadra</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="tarjeta-cuerpo">
    <p style="color:var(--suave);font-size:12.5px;margin:0">
      El contraste compara la suma del detalle descargado con los totales que declara el propio
      SIRE. Si no cuadra, el detalle está incompleto y no conviene seguir a la siguiente fase.
    </p>
  </div>
</div>
<?php endif; ?>

<?php if ($sincronizados): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Períodos ya traídos</h2></div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr><th>Período</th><th class="num">Ventas</th><th class="num">Compras</th><th>Última sincronización</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sincronizados as $per => $s): ?>
        <tr>
          <td><strong><?= e($fmtPer($per)) ?></strong></td>
          <td class="num"><?= (int) $s['ventas'] ?></td>
          <td class="num"><?= (int) $s['compras'] ?></td>
          <td><?= Vista::fecha($s['ultima'], true) ?></td>
          <td><a class="btn btn-sm btn-gris" href="<?= url('sunat_comprobantes.php?periodo=' . $per) ?>">Ver detalle</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Comprobantes guardados (<?= (int) $totales['documentos'] ?>)</h2>
    <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaCpe','comprobantes_sunat')">Exportar CSV</button>
  </div>

  <div class="tarjeta-cuerpo">
    <form class="filtros" method="get">
      <div class="campo">
        <label>Período</label>
        <select name="periodo">
          <option value="">Todos</option>
          <?php foreach ($sincronizados as $per => $s): ?>
            <option value="<?= e($per) ?>" <?= (string) $filtros['periodo'] === (string) $per ? 'selected' : '' ?>><?= e($fmtPer($per)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Tipo</label>
        <select name="tipo">
          <option value="">Ventas y compras</option>
          <option value="ventas"  <?= $filtros['tipo'] === 'ventas'  ? 'selected' : '' ?>>Ventas</option>
          <option value="compras" <?= $filtros['tipo'] === 'compras' ? 'selected' : '' ?>>Compras</option>
        </select>
      </div>
      <div class="campo">
        <label>Documento</label>
        <select name="cod">
          <option value="">Todos</option>
          <?php foreach (['01' => 'Factura', '03' => 'Boleta', '07' => 'Nota de crédito', '08' => 'Nota de débito'] as $c => $n): ?>
            <option value="<?= $c ?>" <?= $filtros['cod'] === $c ? 'selected' : '' ?>><?= e($n) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Buscar</label>
        <input type="text" name="q" value="<?= e($filtros['q']) ?>" placeholder="Serie, número, RUC o nombre">
      </div>
      <button class="btn">Filtrar</button>
      <a class="btn btn-gris" href="<?= url('sunat_comprobantes.php') ?>">Limpiar</a>
    </form>
  </div>

  <div class="tabla-scroll">
    <?php if (!$comprobantes): ?>
      <p class="vacio">No hay comprobantes guardados con esos criterios. Traiga un período del SIRE.</p>
    <?php else: ?>
    <table class="tabla" id="tablaCpe">
      <thead><tr>
        <th>Tipo</th><th>Documento</th><th>Serie-Número</th><th>Fecha</th>
        <th>RUC</th><th>Contraparte</th>
        <th class="num">Base</th><th class="num">IGV</th><th class="num">Total</th><th>Moneda</th>
      </tr></thead>
      <tbody>
      <?php foreach ($comprobantes as $c): ?>
        <tr>
          <td><span class="badge <?= $c['tipo'] === 'ventas' ? 'badge-ok' : '' ?>"><?= e(ucfirst($c['tipo'])) ?></span></td>
          <td><?= e(SunatComprobante::tipoDoc($c['cod_tipo_cdp'])) ?></td>
          <td><strong><?= e($c['serie']) ?>-<?= e($c['numero']) ?></strong></td>
          <td><?= Vista::fecha($c['fecha_emision']) ?></td>
          <td><?= e($c['ruc_contraparte'] ?? '—') ?></td>
          <td style="white-space:normal;max-width:260px"><?= e($c['nombre_contraparte'] ?? '—') ?></td>
          <td class="num"><?= Vista::num($c['base_gravada']) ?></td>
          <td class="num"><?= Vista::num($c['igv']) ?></td>
          <td class="num"><strong><?= Vista::num($c['total']) ?></strong></td>
          <td><?= e($c['moneda'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <th colspan="6" class="num">TOTALES</th>
        <th class="num"><?= Vista::num($totales['base']) ?></th>
        <th class="num"><?= Vista::num($totales['igv']) ?></th>
        <th class="num"><?= Vista::num($totales['total']) ?></th>
        <th></th>
      </tr></tfoot>
    </table>
    <?php endif; ?>
  </div>
</div>
