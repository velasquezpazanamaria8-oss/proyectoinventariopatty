<?php
$abierto = $inv['estado'] === 'ABIERTO';
$puede   = Auth::puede('inventario.gestionar');
$r       = $inv['resumen'];

// Filtro de visualización sobre el detalle ya cargado
$detalle = array_filter($inv['detalle'], function ($d) use ($filtro) {
    return match ($filtro) {
        'pendientes'  => $d['stock_fisico'] === null,
        'diferencias' => $d['stock_fisico'] !== null && (float) $d['diferencia'] != 0.0,
        default       => true,
    };
});
?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Conteo <?= e($inv['codigo']) ?> — <?= e($inv['almacen']) ?>
      <span class="badge <?= $abierto ? 'badge-warn' : 'badge-ok' ?>"><?= e($inv['estado']) ?></span>
    </h2>
    <div class="acciones">
      <a class="btn btn-sm btn-gris" href="<?= url('exportar.php?r=inventario_fisico&f=pdf&inventario_id=' . $inv['id']) ?>">PDF</a>
      <a class="btn btn-sm btn-gris" href="<?= url('exportar.php?r=inventario_fisico&f=xlsx&inventario_id=' . $inv['id']) ?>">Excel</a>
      <a class="btn btn-sm btn-gris" href="<?= url('inventario_fisico.php') ?>">Volver</a>
    </div>
  </div>

  <div class="tarjeta-cuerpo">
    <div class="kpis">
      <div class="kpi"><div class="etiqueta">Productos</div><div class="valor"><?= (int) $r['items'] ?></div></div>
      <div class="kpi exito"><div class="etiqueta">Contados</div><div class="valor"><?= (int) $r['contados'] ?></div></div>
      <div class="kpi alerta"><div class="etiqueta">Pendientes</div><div class="valor"><?= (int) $r['pendientes'] ?></div></div>
      <div class="kpi"><div class="etiqueta">Sobrantes</div><div class="valor"><?= (int) $r['sobrantes'] ?></div></div>
      <div class="kpi peligro"><div class="etiqueta">Faltantes</div><div class="valor"><?= (int) $r['faltantes'] ?></div></div>
      <div class="kpi"><div class="etiqueta">Impacto valorizado</div>
        <div class="valor" style="font-size:20px"><?= Empresa::simbolo() ?> <?= Vista::num($r['impacto']) ?></div></div>
    </div>

    <p style="color:var(--suave);margin:0">
      Fecha: <strong><?= Vista::fecha($inv['fecha']) ?></strong> ·
      Responsable: <strong><?= e($inv['usuario_nombre']) ?></strong>
      <?php if ($inv['observacion']): ?> · <?= e($inv['observacion']) ?><?php endif; ?>
      <?php if (!$abierto && $inv['cerrado_en']): ?>
        · Cerrado el <strong><?= Vista::fecha($inv['cerrado_en'], true) ?></strong>
      <?php endif; ?>
    </p>
  </div>
</div>

<?php if (!$abierto): ?>
  <div class="alerta alerta-info">
    Este conteo está cerrado. Las diferencias ya se aplicaron como ajustes de inventario
    y quedaron registradas en el kardex con el documento <strong><?= e($inv['codigo']) ?></strong>.
    Un conteo cerrado no se reabre: para corregir algo, registre un ajuste nuevo.
  </div>
<?php endif; ?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Detalle del conteo</h2>
    <div class="acciones">
      <a class="btn btn-sm <?= $filtro === 'todos' ? '' : 'btn-gris' ?>"
         href="<?= url('inventario_fisico.php?a=ver&id=' . $inv['id'] . '&ver=todos') ?>">Todos</a>
      <a class="btn btn-sm <?= $filtro === 'pendientes' ? '' : 'btn-gris' ?>"
         href="<?= url('inventario_fisico.php?a=ver&id=' . $inv['id'] . '&ver=pendientes') ?>">Pendientes</a>
      <a class="btn btn-sm <?= $filtro === 'diferencias' ? '' : 'btn-gris' ?>"
         href="<?= url('inventario_fisico.php?a=ver&id=' . $inv['id'] . '&ver=diferencias') ?>">Con diferencia</a>
    </div>
  </div>

  <form method="post" id="formConteo">
    <?= Csrf::campo() ?>
    <input type="hidden" name="op" value="contar">
    <input type="hidden" name="inventario_id" value="<?= (int) $inv['id'] ?>">

    <?php if ($abierto && $puede): ?>
    <div class="tarjeta-cuerpo" style="padding-bottom:0">
      <div class="campo" style="max-width:420px">
        <label>Buscar en el detalle (código o descripción)</label>
        <input type="text" id="buscarFila" placeholder="Filtra las filas de abajo mientras escribe...">
      </div>
    </div>
    <?php endif; ?>

    <div class="tabla-scroll">
      <?php if (!$detalle): ?>
        <p class="vacio">No hay líneas que mostrar con ese filtro.</p>
      <?php else: ?>
      <table class="tabla" id="tablaConteo">
        <thead><tr>
          <th>Código</th><th>Producto</th><th>Und</th>
          <th class="num">Sistema</th>
          <th class="num" style="width:130px">Físico contado</th>
          <th class="num">Diferencia</th>
          <th class="num">Impacto</th>
          <th>Estado</th>
        </tr></thead>
        <tbody>
        <?php foreach ($detalle as $d): ?>
          <?php
            $contado = $d['stock_fisico'] !== null;
            $dif     = (float) ($d['diferencia'] ?? 0);
            $impacto = $contado ? $dif * (float) $d['costo_promedio'] : 0;
          ?>
          <tr data-buscar="<?= e(mb_strtolower($d['codigo'] . ' ' . $d['descripcion'])) ?>">
            <td><?= e($d['codigo']) ?></td>
            <td><?= e($d['descripcion']) ?></td>
            <td><?= e($d['unidad']) ?></td>
            <td class="num"><?= Vista::num($d['stock_sistema']) ?></td>
            <td class="num">
              <?php if ($abierto && $puede): ?>
                <input type="number" step="0.0001" min="0" style="text-align:right"
                       name="fisico[<?= (int) $d['id'] ?>]"
                       data-sistema="<?= e($d['stock_sistema']) ?>"
                       data-costo="<?= e($d['costo_promedio']) ?>"
                       value="<?= $contado ? e($d['stock_fisico']) : '' ?>"
                       placeholder="—">
              <?php else: ?>
                <?= $contado ? Vista::num($d['stock_fisico']) : '—' ?>
              <?php endif; ?>
            </td>
            <td class="num celda-dif">
              <?php if ($contado): ?>
                <strong style="color:<?= $dif > 0 ? 'var(--ok)' : ($dif < 0 ? 'var(--error)' : 'inherit') ?>">
                  <?= ($dif > 0 ? '+' : '') . Vista::num($dif) ?></strong>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="num celda-imp"><?= $contado ? Vista::num($impacto) : '—' ?></td>
            <td class="celda-est">
              <?php if (!$contado): ?><span class="badge badge-warn">Sin contar</span>
              <?php elseif ($dif > 0): ?><span class="badge badge-ok">Sobrante</span>
              <?php elseif ($dif < 0): ?><span class="badge badge-error">Faltante</span>
              <?php else: ?><span class="badge">Coincide</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php if ($abierto && $puede): ?>
    <div class="tarjeta-cuerpo" style="border-top:1px solid var(--linea)">
      <p style="color:var(--suave);margin-top:0;font-size:12.5px">
        Deje en blanco los productos que no se contaron: al cerrar, su stock <strong>no</strong> se modifica.
        Un producto contado en 0 sí genera ajuste hasta dejarlo en cero.
      </p>
      <div class="acciones">
        <button class="btn" type="submit">Guardar conteo</button>
      </div>
    </div>
    <?php endif; ?>
  </form>
</div>

<?php if ($abierto && $puede): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Cerrar y conciliar</h2></div>
  <div class="tarjeta-cuerpo">
    <div class="alerta alerta-warning">
      Al cerrar se generan <strong><?= (int) ($r['sobrantes'] + $r['faltantes']) ?></strong> ajuste(s) de inventario
      para igualar el stock del sistema con lo contado, con un impacto valorizado de
      <strong><?= Empresa::simbolo() ?> <?= Vista::num($r['impacto']) ?></strong>.
      <?php if ($r['pendientes'] > 0): ?>
        Hay <strong><?= (int) $r['pendientes'] ?></strong> producto(s) sin contar que no se tocarán.
      <?php endif; ?>
      Esta operación no se puede deshacer.
    </div>
    <div class="acciones">
      <form method="post" style="display:inline">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="cerrar">
        <input type="hidden" name="inventario_id" value="<?= (int) $inv['id'] ?>">
        <button class="btn btn-verde" type="submit"
                data-confirmar="¿Cerrar el conteo <?= e($inv['codigo']) ?> y aplicar los ajustes? No se puede deshacer.">
          Cerrar y aplicar ajustes
        </button>
      </form>
      <form method="post" style="display:inline">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="anular">
        <input type="hidden" name="inventario_id" value="<?= (int) $inv['id'] ?>">
        <button class="btn btn-rojo" type="submit"
                data-confirmar="¿Anular el conteo <?= e($inv['codigo']) ?>? Se descarta sin tocar el stock.">
          Anular conteo
        </button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  // Filtrado de filas en vivo
  var buscar = document.getElementById('buscarFila');
  if (buscar) {
    buscar.addEventListener('input', function () {
      var q = this.value.trim().toLowerCase();
      document.querySelectorAll('#tablaConteo tbody tr').forEach(function (tr) {
        tr.style.display = (!q || tr.dataset.buscar.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }

  // Diferencia e impacto recalculados mientras se escribe
  document.querySelectorAll('#tablaConteo input[name^="fisico"]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var tr  = inp.closest('tr');
      var sis = parseFloat(inp.dataset.sistema) || 0;
      var cos = parseFloat(inp.dataset.costo) || 0;
      var celdaDif = tr.querySelector('.celda-dif'),
          celdaImp = tr.querySelector('.celda-imp'),
          celdaEst = tr.querySelector('.celda-est');

      if (inp.value === '') {
        celdaDif.innerHTML = '—';
        celdaImp.textContent = '—';
        celdaEst.innerHTML = '<span class="badge badge-warn">Sin contar</span>';
        return;
      }
      var dif = (parseFloat(inp.value) || 0) - sis;
      var color = dif > 0 ? 'var(--ok)' : (dif < 0 ? 'var(--error)' : 'inherit');
      celdaDif.innerHTML = '<strong style="color:' + color + '">' + (dif > 0 ? '+' : '') + fmt(dif) + '</strong>';
      celdaImp.textContent = fmt(dif * cos);
      celdaEst.innerHTML = dif > 0
        ? '<span class="badge badge-ok">Sobrante</span>'
        : (dif < 0 ? '<span class="badge badge-error">Faltante</span>'
                   : '<span class="badge">Coincide</span>');
    });
  });
})();
</script>
