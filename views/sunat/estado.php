<?php $fmt = fn(string $p): string => substr($p, 4, 2) . '/' . substr($p, 0, 4); ?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Estado de la integración con SUNAT</h2>
    <form method="post" style="display:inline">
      <?= Csrf::campo() ?>
      <input type="hidden" name="op" value="ejecutar">
      <button class="btn btn-sm btn-verde" type="submit">Ejecutar sincronización ahora</button>
    </form>
  </div>

  <div class="tarjeta-cuerpo">
    <p style="color:var(--suave);margin-top:0">
      Cada período recorre cuatro etapas: <strong>traer del SIRE</strong> →
      <strong>descargar</strong> → <strong>conciliar</strong> → <strong>generar movimientos</strong>.
      Aquí se ve dónde está cada uno y qué lo detiene.
    </p>
  </div>

  <div class="tabla-scroll">
    <?php if (!$periodos): ?>
      <p class="vacio">Todavía no se ha traído ningún período del SIRE.</p>
    <?php else: ?>
    <table class="tabla">
      <thead><tr>
        <th>Período</th><th class="num">Comprobantes</th><th class="num">Descargados</th>
        <th class="num">Líneas</th><th class="num">Sin conciliar</th><th class="num">Con movimiento</th>
        <th>Siguiente paso</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($periodos as $per => $p): ?>
        <?php
          // El primer bloqueo que encuentre es el que hay que resolver.
          if ($p['sin_descargar'] > 0) {
              $paso = ['Faltan ' . $p['sin_descargar'] . ' por descargar', 'badge-warn', 'sunat_descargas.php?periodo=' . $per, 'Descargar'];
          } elseif ($p['sin_conciliar'] > 0) {
              $paso = [$p['sin_conciliar'] . ' línea(s) sin conciliar', 'badge-warn', 'sunat_conciliar.php?periodo=' . $per, 'Conciliar'];
          } elseif ($p['generados'] < $p['comprobantes']) {
              $paso = ['Faltan movimientos por generar', 'badge-warn', 'sunat_generar.php', 'Generar'];
          } else {
              $paso = ['Completo', 'badge-ok', null, null];
          }
        ?>
        <tr>
          <td><strong><?= e($fmt($per)) ?></strong><br>
              <small style="color:var(--suave)"><?= (int) $p['ventas'] ?> v · <?= (int) $p['compras'] ?> c</small></td>
          <td class="num"><?= (int) $p['comprobantes'] ?></td>
          <td class="num"><?= (int) $p['descargados'] ?>
              <?php if ($p['sin_descargar'] > 0): ?>
                <span class="badge badge-warn">−<?= (int) $p['sin_descargar'] ?></span>
              <?php endif; ?></td>
          <td class="num"><?= (int) $p['lineas'] ?></td>
          <td class="num"><?= (int) $p['sin_conciliar'] ?></td>
          <td class="num"><?= (int) $p['generados'] ?></td>
          <td><span class="badge <?= $paso[1] ?>"><?= e($paso[0]) ?></span></td>
          <td>
            <?php if ($paso[2]): ?>
              <a class="btn btn-sm btn-gris" href="<?= url($paso[2]) ?>"><?= e($paso[3]) ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Sincronización automática</h2></div>
  <div class="tarjeta-cuerpo">
    <?php if (strlen($cronClave) < 16): ?>
      <div class="alerta alerta-warning">
        No hay clave de cron configurada. Añada en <code>config.php</code> una
        <code>'cron_clave'</code> de al menos 16 caracteres dentro de <code>'app'</code>.
        Sin ella <code>cron.php</code> se niega a ejecutarse, porque esa URL es pública.
      </div>
    <?php else: ?>
      <p style="margin-top:0">Configure en Hostinger una tarea programada diaria con:</p>
      <pre style="background:#f8fafc;border:1px solid var(--linea);border-radius:6px;padding:10px;
                  overflow:auto;font-size:12.5px"><?= e(
        '/usr/bin/php ' . BASE_PATH . '/cron.php') ?></pre>
      <p style="color:var(--suave);font-size:12.5px">
        O por URL, si su plan sólo permite eso:
<?php // Vista::base(), no la config: con base_url = null (el valor por defecto)
              // Config::get devuelve null y la URL saldría sin la subcarpeta. ?>
        <code><?= e(Vista::base()) ?>/cron.php?clave=•••</code>
        (la clave está en <code>config.php</code>; no se muestra aquí).
      </p>
    <?php endif; ?>

    <p style="color:var(--suave);font-size:12.5px">
      La pasada automática <strong>trae del SIRE y descarga</strong>, pero <strong>no genera
      movimientos de inventario</strong>: eso mueve existencias y exige que alguien haya
      conciliado los productos y revisado el saldo inicial.
    </p>
  </div>

  <div class="tabla-scroll">
    <?php if (!$historial): ?>
      <p class="vacio">Todavía no se ha ejecutado ninguna sincronización.</p>
    <?php else: ?>
    <table class="tabla">
      <thead><tr><th>Inicio</th><th>Origen</th><th>Estado</th><th>Resultado</th><th>Duración</th></tr></thead>
      <tbody>
      <?php foreach ($historial as $h): ?>
        <tr>
          <td><?= Vista::fecha($h['iniciado_en'], true) ?></td>
          <td><?= e($h['origen']) ?></td>
          <td><span class="badge <?= $h['estado'] === 'OK' ? 'badge-ok' : ($h['estado'] === 'ERROR' ? 'badge-error' : 'badge-warn') ?>"><?= e($h['estado']) ?></span></td>
          <td style="white-space:normal;max-width:420px"><?= e($h['resumen'] ?? '—') ?></td>
          <td><?= $h['terminado_en']
                 ? (strtotime($h['terminado_en']) - strtotime($h['iniciado_en'])) . ' s'
                 : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($fallidos): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Comprobantes que SUNAT no entregó (<?= count($fallidos) ?>)</h2></div>
  <div class="tarjeta-cuerpo">
    <div class="alerta alerta-info">
      Tras 3 intentos se deja de pedir un comprobante, pero <strong>la exclusión caduca a los
      <?= SunatCpeItem::DIAS_CADUCIDAD ?> días</strong>: SUNAT publica con retraso cuando el emisor
      transmite tarde, y darlo por perdido para siempre cementaría como inexistente algo que sí
      acabó existiendo. Con el botón se reintenta ya, sin esperar.
    </div>
  </div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr>
        <th>Período</th><th>Tipo</th><th>Documento</th><th class="num">Intentos</th>
        <th>Último intento</th><th>Motivo</th><th></th>
      </tr></thead>
      <tbody>
      <?php $vistos = []; foreach ($fallidos as $f): ?>
        <tr>
          <td><?= e($fmt($f['periodo'])) ?></td>
          <td><?= e(ucfirst($f['tipo'])) ?></td>
          <td><?= e($f['serie']) ?>-<?= e($f['numero']) ?></td>
          <td class="num"><?= (int) $f['descarga_intentos'] ?></td>
          <td><?= Vista::fecha($f['descargado_en'], true) ?></td>
          <td style="white-space:normal;max-width:400px"><?= e($f['descarga_msg']) ?></td>
          <td>
            <?php if (!isset($vistos[$f['periodo']])): $vistos[$f['periodo']] = true; ?>
              <form method="post" style="display:inline">
                <?= Csrf::campo() ?>
                <input type="hidden" name="op" value="reintentar">
                <input type="hidden" name="periodo" value="<?= e($f['periodo']) ?>">
                <button class="btn btn-sm btn-gris" type="submit">Reintentar período</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
