<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Generar movimientos de inventario</h2></div>

  <div class="tarjeta-cuerpo">
    <div class="alerta alerta-warning">
      <strong>Esta pantalla sí modifica el stock.</strong> Convierte cada comprobante en una entrada
      o una salida, en <strong>orden cronológico</strong> mezclando compras y ventas, tal como
      ocurrieron. Es la única forma de que el kardex quede coherente.
      <br><br>
      Cada comprobante recuerda qué movimiento generó, así que volver a ejecutar
      <strong>no duplica</strong>. Si uno falla se anota el motivo y se sigue con el resto.
    </div>

    <div class="kpis">
      <div class="kpi"><div class="etiqueta">Por convertir</div><div class="valor"><?= (int) $revision['total'] ?></div></div>
      <div class="kpi exito"><div class="etiqueta">Serán entradas</div><div class="valor"><?= (int) $revision['entradas'] ?></div></div>
      <div class="kpi alerta"><div class="etiqueta">Serán salidas</div><div class="valor"><?= (int) $revision['salidas'] ?></div></div>
      <div class="kpi"><div class="etiqueta">Ya convertidos</div><div class="valor"><?= (int) $revision['ya_generados'] ?></div></div>
      <div class="kpi peligro"><div class="etiqueta">Sin líneas útiles</div><div class="valor"><?= (int) $revision['sin_lineas'] ?></div></div>
    </div>

    <?php if ($revision['primera']): ?>
      <p style="color:var(--suave);margin-top:0">
        Se procesarán <?= (int) $revision['lineas'] ?> línea(s) entre el
        <strong><?= Vista::fecha($revision['primera']) ?></strong> y el
        <strong><?= Vista::fecha($revision['ultima']) ?></strong>.
      </p>
    <?php endif; ?>

    <?php if ($revision['sin_conciliar'] > 0): ?>
      <div class="alerta alerta-error">
        Hay <strong><?= (int) $revision['sin_conciliar'] ?></strong> comprobante(s) con líneas
        <strong>sin conciliar</strong>. Esas líneas <strong>no moverán stock</strong> y el
        inventario quedará incompleto.
        <a href="<?= url('sunat_conciliar.php') ?>">Conciliar ahora</a> antes de continuar.
      </div>
    <?php endif; ?>

    <div class="campo" style="max-width:340px">
      <label>Almacén donde se registrarán los movimientos</label>
      <select onchange="location.href='<?= url('sunat_generar.php?almacen_id=') ?>'+this.value">
        <?php foreach ($almacenes as $id => $nom): ?>
          <option value="<?= $id ?>" <?= $id === $almacenId ? 'selected' : '' ?>><?= e($nom) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="tarjeta-cuerpo" style="border-top:1px solid var(--linea)">
    <h3 style="font-size:14px;margin-top:0">Paso 1 — Saldo inicial</h3>

    <?php /* Ya aplicado y sin valor: el kardex lo tiene registrado a costo cero
             y eso no se arregla solo. Se dice claramente, con el número. */
    if ($inicialAplicadoSinCosto > 0): ?>
      <div class="alerta alerta-warning" style="font-size:13px">
        <strong><?= (int) $inicialAplicadoSinCosto ?></strong> producto(s) ya tienen su saldo
        inicial en el kardex <strong>a costo 0</strong>. Sus unidades figuran en el stock pero
        no suman nada al valor del inventario, y al venderlas el costo de ventas saldrá
        demasiado bajo. Corríjalo con un <a href="<?= url('ajustes.php') ?>">ajuste</a> de
        valorización, o deshaga esos movimientos antes de seguir cargando historia encima.
      </div>
    <?php endif; ?>
    <?php if ($inicialPendiente > 0): ?>
      <p style="color:var(--suave);margin-top:0">
        Hay <strong><?= (int) $inicialPendiente ?></strong> producto(s) con saldo inicial capturado
        y sin aplicar. Se registrará como carga inicial <strong>con fecha anterior al primer
        comprobante</strong>, para que las ventas de productos comprados antes del período no
        fallen por falta de stock.
      </p>
      <?php /* Aplicar un saldo inicial sin costo mete las unidades al kardex
               sin valor: el inventario sale valorizado por debajo y el costo de
               ventas queda mal. Mejor avisarlo antes de que sea historia. */
      if ($inicialSinCosto > 0): ?>
        <div class="alerta alerta-error" style="font-size:13px">
          <strong><?= (int) $inicialSinCosto ?></strong> de esos productos tienen cantidad
          pero <strong>costo 0</strong>. Si los aplica así, entrarán al inventario sin valor.
          <a href="<?= url('sunat_conciliar.php?almacen_id=' . $almacenId) ?>">Poner el costo
          antes</a> — la pantalla propone el de sus propias compras.
        </div>
      <?php endif; ?>
      <form method="post">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="inicial">
        <input type="hidden" name="almacen_id" value="<?= (int) $almacenId ?>">
        <button class="btn btn-verde" type="submit"
                data-confirmar="Se registrará el saldo inicial en el kardex. ¿Continuar?">
          Aplicar saldo inicial (<?= (int) $inicialPendiente ?>)
        </button>
      </form>
    <?php else: ?>
      <p style="color:var(--suave);margin:0">
        No hay saldo inicial pendiente.
        <a href="<?= url('sunat_conciliar.php?almacen_id=' . $almacenId) ?>">Capturarlo aquí</a>
        si algún producto ya tenía existencias antes de la importación.
      </p>
    <?php endif; ?>
  </div>

  <div class="tarjeta-cuerpo" style="border-top:1px solid var(--linea)">
    <h3 style="font-size:14px;margin-top:0">Paso 2 — Convertir los comprobantes</h3>
    <?php if ($inicialPendiente > 0): ?>
      <div class="alerta alerta-error">
        Aplique primero el saldo inicial. Convertir antes dejaría el kardex con saldos
        incoherentes: el saldo inicial se fecha <em>antes</em> de estos movimientos, y el kardex
        guarda el saldo del momento en que se registra cada uno.
      </div>
    <?php elseif ($revision['total'] > 0): ?>
      <form method="post">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="generar">
        <input type="hidden" name="almacen_id" value="<?= (int) $almacenId ?>">
        <button class="btn" type="submit"
                data-confirmar="Se generarán movimientos de inventario reales. ¿Continuar?">
          Convertir siguiente lote (25 de <?= (int) $revision['total'] ?>)
        </button>
        <span style="color:var(--suave);font-size:12.5px">
          Por lotes para no agotar el tiempo del servidor. Pulse otra vez para continuar.
        </span>
      </form>
    <?php else: ?>
      <p style="color:var(--suave);margin:0">No queda ningún comprobante por convertir.</p>
    <?php endif; ?>
  </div>

  <?php /* Empezar de nuevo. El kardex es de sólo añadir, así que una carga
           inicial mal valorizada no se corrige por encima: o se ajusta, o se
           rehace. Esto último sólo es sensato mientras haya poco encima. */
  if ($revision['ya_generados'] > 0 || $inicialAplicado > 0): ?>
    <div class="tarjeta-cuerpo" style="border-top:1px solid var(--linea)">
      <h3 style="font-size:14px;margin-top:0">Empezar de nuevo</h3>
      <p style="color:var(--suave);margin-top:0">
        Borra <strong>todos</strong> los movimientos que generó esta pantalla
        (<?= (int) $inicialAplicado ?> de saldo inicial y <?= (int) $revision['ya_generados'] ?>
        comprobante(s) convertidos) y deja el stock en cero. Sirve cuando la carga inicial entró
        con costos equivocados: el kardex sólo admite añadir, así que corregir el valor de
        partida obliga a rehacerlo.
      </p>
      <p style="color:var(--suave);margin-top:0;font-size:12.5px">
        <strong>No se pierde nada de antes:</strong> el catálogo, los comprobantes, sus líneas,
        las equivalencias ya decididas, los archivos y las cantidades del saldo inicial se
        conservan. Sólo hay que revisar los costos y volver a aplicar.
      </p>
      <?php /* Antes de rehacerlo todo suele bastar con recalcular: si un
               comprobante se convirtió tarde, lo único que quedó mal es la
               columna de saldos, que es un dato derivado. */ ?>
      <form method="post" style="margin-bottom:14px">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="recalcular">
        <input type="hidden" name="almacen_id" value="<?= (int) $almacenId ?>">
        <button class="btn btn-gris" type="submit">Recalcular los saldos del kardex</button>
        <span style="color:var(--suave);font-size:12.5px">
          Reordena la columna de saldos por fecha. Un comprobante que falló y se convirtió
          después queda grabado fuera de orden y el histórico deja de cuadrar al leerlo.
          No cambia cantidades ni el valor del inventario.
        </span>
      </form>

      <form method="post">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="deshacer">
        <input type="hidden" name="almacen_id" value="<?= (int) $almacenId ?>">
        <button class="btn btn-rojo" type="submit"
                data-confirmar="Se borrarán todos los movimientos de inventario generados y el stock volverá a cero. Los comprobantes y el catálogo no se tocan. ¿Continuar?">
          Deshacer los movimientos generados
        </button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php if ($resultado): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Resultado del último lote</h2></div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr><th>Comprobante</th><th>Resultado</th><th>Detalle</th></tr></thead>
      <tbody>
      <?php foreach ($resultado as $h): ?>
        <tr>
          <td><strong><?= e($h['doc']) ?></strong></td>
          <td><?php if ($h['ok']): ?>
                <span class="badge badge-ok"><?= e($h['tipo']) ?></span>
              <?php else: ?><span class="badge badge-error">No convertido</span><?php endif; ?></td>
          <td style="white-space:normal"><?= $h['ok'] ? (int) $h['lineas'] . ' línea(s)' : e($h['msg']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($fallidos): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Comprobantes no convertidos (<?= count($fallidos) ?>)</h2></div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr><th>Fecha</th><th>Tipo</th><th>Documento</th><th>Motivo</th></tr></thead>
      <tbody>
      <?php foreach ($fallidos as $f): ?>
        <tr>
          <td><?= Vista::fecha($f['fecha_emision']) ?></td>
          <td><?= e(ucfirst($f['tipo'])) ?></td>
          <td><?= e($f['serie']) ?>-<?= e($f['numero']) ?></td>
          <td style="white-space:normal;max-width:520px"><?= e($f['mov_msg']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="tarjeta-cuerpo">
    <p style="color:var(--suave);font-size:12.5px;margin:0">
      «Stock insuficiente» suele significar que falta capturar el saldo inicial de ese producto, o
      que su compra está en un período anterior que todavía no se importó. Corregido eso, vuelva a
      pulsar «Convertir»: se reintentan solos.
    </p>
  </div>
</div>
<?php endif; ?>

<?php if ($generados): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Movimientos generados</h2></div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr>
        <th>Fecha</th><th>Comprobante SUNAT</th><th>Origen</th>
        <th>Movimiento</th><th class="num">Líneas</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($generados as $g): ?>
        <tr>
          <td><?= Vista::fecha($g['fecha_emision']) ?></td>
          <td><?= e($g['serie']) ?>-<?= e($g['numero']) ?>
              <small style="color:var(--suave)"><?= e(SunatComprobante::tipoDoc($g['cod_tipo_cdp'])) ?></small></td>
          <td><span class="badge <?= $g['tipo'] === 'ventas' ? 'badge-ok' : '' ?>"><?= e(ucfirst($g['tipo'])) ?></span></td>
          <td><strong><?= e($g['movimiento'] ?? '—') ?></strong></td>
          <td class="num"><?= (int) $g['items_cant'] ?></td>
          <td>
            <a class="btn btn-sm btn-gris"
               href="<?= url(($g['mov_tabla'] === 'entradas' ? 'entradas.php' : 'salidas.php') . '?a=ver&id=' . (int) $g['mov_id']) ?>">Ver</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
