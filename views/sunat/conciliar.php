<?php $fmt = fn(string $p): string => substr($p, 4, 2) . '/' . substr($p, 0, 4); ?>

<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Conciliar productos de SUNAT con el catálogo</h2></div>
  <div class="tarjeta-cuerpo">
    <div class="alerta alerta-info">
      Cada línea de un comprobante tiene que apuntar a un producto del catálogo para poder mover
      stock. Aquí se decide una vez por producto y <strong>queda aprendido</strong>: las próximas
      importaciones lo reconocen solo.
      <br><br>
      El emparejamiento se guarda por <strong>código del emisor + su RUC</strong>, porque el código
      8863 de un proveedor no es el mismo artículo que el 8863 de otro. En las ventas el código es
      de la propia empresa, así que suele coincidir directo con el catálogo.
    </div>

    <div class="kpis">
      <div class="kpi"><div class="etiqueta">Productos distintos</div><div class="valor"><?= (int) $avance['total'] ?></div></div>
      <div class="kpi exito"><div class="etiqueta">Emparejados</div><div class="valor"><?= (int) $avance['mapeados'] ?></div></div>
      <div class="kpi alerta"><div class="etiqueta">Sin decidir</div><div class="valor"><?= (int) $avance['sin_decidir'] ?></div></div>
      <div class="kpi"><div class="etiqueta">Ignorados</div><div class="valor"><?= (int) $avance['ignorados'] ?></div></div>
    </div>

    <form class="filtros" method="get">
      <div class="campo">
        <label>Período</label>
        <select name="periodo" onchange="this.form.submit()">
          <option value="">Todos</option>
          <?php foreach ($periodos as $p => $s): ?>
            <option value="<?= e($p) ?>" <?= (string) $filtros['periodo'] === (string) $p ? 'selected' : '' ?>><?= e($fmt($p)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Origen</label>
        <select name="tipo" onchange="this.form.submit()">
          <option value="">Ventas y compras</option>
          <option value="compras" <?= $filtros['tipo'] === 'compras' ? 'selected' : '' ?>>Sólo compras</option>
          <option value="ventas"  <?= $filtros['tipo'] === 'ventas'  ? 'selected' : '' ?>>Sólo ventas</option>
        </select>
      </div>
      <input type="hidden" name="almacen_id" value="<?= (int) $almacenId ?>">
    </form>

    <?php if ($avance['sugeridos'] > 0): ?>
      <form method="post" style="margin-top:10px">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="sugerencias">
        <input type="hidden" name="periodo" value="<?= e($filtros['periodo']) ?>">
        <input type="hidden" name="almacen_id" value="<?= (int) $almacenId ?>">
        <button class="btn btn-verde" type="submit">
          Emparejar automáticamente <?= (int) $avance['sugeridos'] ?> por código exacto
        </button>
        <span style="color:var(--suave);font-size:12.5px">
          Sólo se aplica cuando el código coincide exactamente. Las coincidencias por descripción
          se muestran como pista, pero las decide usted.
        </span>
      </form>
    <?php endif; ?>
  </div>

  <div class="tabla-scroll">
    <?php if (!$items): ?>
      <p class="vacio">No hay productos por conciliar. Descargue comprobantes primero.</p>
    <?php else: ?>
    <table class="tabla" id="tablaConc">
      <thead><tr>
        <th>Origen</th><th>Fecha</th><th>Código</th><th>Descripción</th><th>Und</th>
        <th class="num">Líneas</th><th class="num">Cantidad</th><th class="num">Precio</th>
        <th>Producto del catálogo</th><th class="no-export">Acción</th>
      </tr></thead>
      <tbody>
      <?php foreach ($items as $i):
        $estado = $i['ignorado'] ? 'ignorado' : ($i['producto'] ? 'mapeado' : 'pendiente'); ?>
        <tr data-clave="<?= e($i['clave']) ?>" data-ruc="<?= e($i['origen_ruc']) ?>"
            data-cod="<?= e($i['codigo_sunat']) ?>" data-desc="<?= e($i['descripcion']) ?>"
            data-und="<?= e($i['unidad_codigo']) ?>" data-undn="<?= e($i['unidad_nombre']) ?>"
            data-precio="<?= e($i['precio_min']) ?>" data-tipo="<?= e($i['tipo']) ?>">
          <td><span class="badge <?= $i['tipo'] === 'ventas' ? 'badge-ok' : '' ?>"><?= e(ucfirst($i['tipo'])) ?></span></td>
          <td style="white-space:nowrap">
            <?php /* La fila junta varios comprobantes: se muestra el último y,
                     si abarcan más de un día, desde cuándo. */ ?>
            <?= Vista::fecha($i['fecha_hasta']) ?>
            <?php if ($i['fecha_desde'] !== $i['fecha_hasta']): ?>
              <br><small style="color:var(--suave)">desde <?= Vista::fecha($i['fecha_desde']) ?></small>
            <?php endif; ?>
            <?php /* Meses en los que aparece: sólo cuando se están viendo todos
                     los períodos, que es cuando la fila mezcla varios. */
            $meses = array_filter(explode(',', (string) $i['periodos']));
            if (!$filtros['periodo'] && count($meses) > 1): ?>
              <br>
              <?php foreach ($meses as $mes): ?>
                <a class="badge badge-mes" title="Ver sólo este mes"
                   href="<?= url('sunat_conciliar.php?periodo=' . $mes
                        . '&tipo=' . urlencode($filtros['tipo'])
                        . '&almacen_id=' . (int) $almacenId) ?>"><?= e($fmt($mes)) ?></a>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
          <td><?= e($i['codigo_sunat'] ?: '—') ?></td>
          <td style="white-space:normal;max-width:300px">
            <?= e($i['descripcion']) ?>
            <?php if ($i['tipo'] === 'compras'): ?>
              <br><small style="color:var(--suave)"><?= e(mb_substr((string) $i['contraparte'], 0, 34)) ?></small>
            <?php endif; ?>
          </td>
          <td><?= e($i['unidad_codigo'] ?: '—') ?></td>
          <td class="num">
            <button type="button" class="enlace-lineas acc" data-a="comprobantes"
                    title="Ver los comprobantes donde aparece, con su PDF"><?= (int) $i['lineas'] ?></button>
          </td>
          <td class="num"><?= Vista::num($i['cantidad_total']) ?></td>
          <td class="num"><?= Vista::num($i['precio_min'], 2) ?></td>
          <td class="celda-estado" style="white-space:normal;max-width:250px">
            <?php if ($estado === 'mapeado'): ?>
              <span class="badge badge-ok">✓</span> <?= e($i['producto']['codigo']) ?> — <?= e($i['producto']['descripcion']) ?>
            <?php elseif ($estado === 'ignorado'): ?>
              <span class="badge">No es inventario</span>
            <?php elseif ($i['sugerencia']): ?>
              <span class="badge badge-warn">Sugerido (<?= e($i['sugerencia']['motivo']) ?>)</span>
              <?= e($i['sugerencia']['producto']['codigo']) ?> — <?= e($i['sugerencia']['producto']['descripcion']) ?>
              <input type="hidden" class="sug-id" value="<?= (int) $i['sugerencia']['producto']['id'] ?>">
            <?php else: ?>
              <span class="badge badge-warn">Sin decidir</span>
            <?php endif; ?>
          </td>
          <td class="no-export">
            <div class="acciones celda-acciones">
              <button type="button" class="btn btn-sm btn-rojo acc" data-a="comprobantes"
                      title="Ver los comprobantes y su PDF">PDF</button>
              <?php if ($estado === 'pendiente'): ?>
                <?php if ($i['sugerencia']): ?>
                  <button type="button" class="btn btn-sm btn-verde acc" data-a="aceptar">Aceptar</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm acc" data-a="buscar">Buscar</button>
                <button type="button" class="btn btn-sm btn-gris acc" data-a="crear">Crear</button>
                <button type="button" class="btn btn-sm btn-gris acc" data-a="ignorar">Ignorar</button>
              <?php else: ?>
                <button type="button" class="btn btn-sm btn-gris acc" data-a="deshacer">Cambiar</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php if ($stock): ?>
<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Stock inicial antes de la importación</h2>
  </div>
  <form method="post">
    <?= Csrf::campo() ?>
    <input type="hidden" name="op" value="stock_inicial">
    <input type="hidden" name="periodo" value="<?= e($filtros['periodo']) ?>">

    <div class="tarjeta-cuerpo">
      <div class="alerta alerta-warning">
        Las ventas de junio incluyen productos comprados <strong>antes</strong> de junio. Como el
        sistema no permite salidas sin stock, hay que decir con cuánto empezaba cada producto.
        <br><br>
        Al generar los movimientos se creará una <strong>carga inicial</strong> con estas cantidades,
        fechada justo antes del primer comprobante, y recién después se reproduce la historia.
        Dejar un producto en cero es válido si no había existencias.
      </div>

      <div class="campo" style="max-width:320px">
        <label>Almacén</label>
        <select name="almacen_id" onchange="location.href='<?= url('sunat_conciliar.php?almacen_id=') ?>'+this.value">
          <?php foreach ($almacenes as $id => $nom): ?>
            <option value="<?= $id ?>" <?= $id === $almacenId ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="tabla-scroll">
      <table class="tabla">
        <thead><tr>
          <th>Código</th><th>Producto</th><th>Und</th>
          <th class="num" style="width:150px">Stock inicial</th>
          <th class="num" style="width:150px">Costo unitario</th>
        </tr></thead>
        <tbody>
        <?php foreach ($stock as $s): ?>
          <tr>
            <td><?= e($s['codigo']) ?></td>
            <td style="white-space:normal;max-width:340px"><?= e($s['descripcion']) ?></td>
            <td><?= e($s['unidad']) ?></td>
            <td class="num">
              <?php /* Quedarse corto no da un error claro: las ventas de esas
                       fechas fallan por falta de stock, se convierten después y
                       el kardex acaba con saldos negativos a mitad del mes. */
              $minimo   = (float) $s['cantidad_minima'];
              $cantAct  = (float) $s['cantidad'];
              $corto    = $minimo > 0 && $cantAct < $minimo; ?>
              <input type="number" step="0.0001" min="0" style="text-align:right"
                     name="cantidad[<?= (int) $s['id'] ?>]"
                     value="<?= $cantAct > 0 ? e(rtrim(rtrim($s['cantidad'], '0'), '.'))
                                             : ($minimo > 0 ? e(rtrim(rtrim(number_format($minimo, 4, '.', ''), '0'), '.')) : '0') ?>">
              <?php if ($corto): ?>
                <br><small style="color:var(--error)">faltan al menos
                  <?= e(rtrim(rtrim(number_format($minimo, 4, '.', ''), '0'), '.')) ?></small>
              <?php elseif ($cantAct == 0 && $minimo > 0): ?>
                <br><small style="color:var(--suave)">mínimo según sus ventas</small>
              <?php endif; ?>
            </td>
            <td class="num">
              <?php /* Si nunca se puso un costo se propone el de sus compras.
                       Dejarlo en cero mete las unidades al kardex sin valor y
                       el inventario queda valorizado por debajo de lo que es. */
              $sugerido = (float) $s['costo_sugerido'];
              $costoAct = (float) $s['costo_unitario'];
              $valor    = $costoAct > 0 ? $costoAct : $sugerido; ?>
              <input type="number" step="0.0001" min="0" style="text-align:right"
                     class="costo-inicial<?= $costoAct == 0 && $sugerido > 0 ? ' es-sugerido' : '' ?>"
                     name="costo[<?= (int) $s['id'] ?>]"
                     value="<?= $valor > 0 ? e(rtrim(rtrim(number_format($valor, 4, '.', ''), '0'), '.')) : '0' ?>">
              <?php if ($costoAct == 0 && $sugerido > 0): ?>
                <br><small style="color:var(--suave)">sugerido por sus compras</small>
              <?php elseif ($costoAct == 0 && $s['cantidad'] > 0): ?>
                <br><small style="color:var(--error)">sin costo conocido</small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="tarjeta-cuerpo" style="border-top:1px solid var(--linea)">
      <button class="btn" type="submit">Guardar stock inicial</button>
      <span style="color:var(--suave);font-size:12.5px">
        Guardar aquí <strong>no mueve stock todavía</strong>: se aplica al generar los movimientos.
      </span>
    </div>
  </form>
</div>
<?php endif; ?>


<!-- Formulario de alta de producto desde un ítem de SUNAT -->
<div class="modal-fondo" id="modalAlta" style="display:none">
  <div class="modal-caja" style="max-width:640px">
    <div class="modal-cab">
      <h2>Crear producto en el catálogo</h2>
      <button type="button" class="modal-cerrar" id="altaCerrar" aria-label="Cerrar">&times;</button>
    </div>

    <form id="formAlta" class="tarjeta-cuerpo" style="overflow-y:auto">
      <div class="alerta alerta-info" style="font-size:12.5px">
        Los datos vienen del comprobante de SUNAT. Revíselos antes de guardar: la
        <strong>categoría</strong> y la <strong>marca</strong> no llegan en el comprobante y
        conviene ponerlas ahora, porque de ellas dependen los reportes por categoría.
      </div>

      <div class="form-grid">
        <div class="campo">
          <label>Código *</label>
          <input type="text" id="altaCodigo" required maxlength="40">
          <small style="color:var(--suave)" id="altaCodigoNota"></small>
        </div>
        <div class="campo" style="grid-column:span 2">
          <label>Descripción *</label>
          <input type="text" id="altaDescripcion" required maxlength="255">
        </div>
        <div class="campo">
          <label>Categoría</label>
          <select id="altaCategoria">
            <option value="">— Sin categoría —</option>
            <?php foreach ($categorias as $idc => $nomc): ?>
              <option value="<?= $idc ?>"><?= e($nomc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label>Marca</label>
          <select id="altaMarca">
            <option value="">— Sin marca —</option>
            <?php foreach ($marcas as $idm => $nomm): ?>
              <option value="<?= $idm ?>"><?= e($nomm) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label>Unidad *</label>
          <select id="altaUnidad" required>
            <?php foreach ($unidades as $idu => $nomu): ?>
              <option value="<?= $idu ?>"><?= e($nomu) ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:var(--suave)" id="altaUnidadNota"></small>
        </div>
        <div class="campo">
          <label>Precio de compra</label>
          <input type="number" id="altaPCompra" step="0.0001" min="0" value="0">
        </div>
        <div class="campo">
          <label>Precio de venta</label>
          <input type="number" id="altaPVenta" step="0.0001" min="0" value="0">
        </div>
        <div class="campo">
          <label>Stock mínimo</label>
          <input type="number" id="altaMinimo" step="0.0001" min="0" value="0">
        </div>
      </div>

      <p style="color:var(--suave);font-size:12.5px;margin-bottom:0" id="altaOrigen"></p>
    </form>

    <div class="modal-pie">
      <span class="modal-conteo" id="altaAviso"></span>
      <div class="acciones">
        <button type="button" class="btn btn-gris" id="altaCancelar">Cancelar</button>
        <button type="button" class="btn btn-verde" id="altaGuardar">Crear y emparejar</button>
      </div>
    </div>
  </div>
</div>

<!-- Comprobantes en los que aparece un producto, para mirar el PDF antes de decidir -->
<div class="modal-fondo" id="modalCpe" style="display:none">
  <div class="modal-caja" style="max-width:900px">
    <div class="modal-cab">
      <h2 id="cpeTitulo">Comprobantes</h2>
      <button type="button" class="modal-cerrar" id="cpeCerrar" aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal-cuerpo" id="cpeCuerpo">
      <p class="vacio">Consultando...</p>
    </div>
    <div class="modal-pie">
      <span class="modal-conteo" id="cpeNota"></span>
      <button type="button" class="btn btn-gris" id="cpeCerrar2">Cerrar</button>
    </div>
  </div>
</div>

<script>
(function () {
  var tabla = document.getElementById('tablaConc');
  if (!tabla) return;
  var csrf = <?= json_encode(Csrf::token()) ?>;

  function enviar(fila, datos, alTerminar) {
    var cuerpo = new URLSearchParams(Object.assign({
      _csrf: csrf,
      origen_ruc: fila.dataset.ruc,
      clave: fila.dataset.clave,
      descripcion: fila.dataset.desc
    }, datos));

    fila.style.opacity = '.5';
    fetch(window.BASE_URL + 'api/conciliar.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: cuerpo
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        fila.style.opacity = '';
        if (!j.ok) { alert(j.error); return; }
        alTerminar(j);
      })
      .catch(function (e) { fila.style.opacity = ''; alert('No se pudo guardar: ' + e.message); });
  }

  function pintarResuelto(fila, j) {
    var celda = fila.querySelector('.celda-estado');
    if (j.estado === 'ignorado') {
      celda.innerHTML = '<span class="badge">No es inventario</span>';
    } else if (j.estado === 'pendiente') {
      location.reload();      // vuelve a calcular la sugerencia
      return;
    } else {
      celda.innerHTML = '<span class="badge badge-ok">✓</span> ' + j.producto;
    }
    // El botón del PDF se conserva: sigue siendo útil después de decidir.
    fila.querySelector('.celda-acciones').innerHTML =
      '<button type="button" class="btn btn-sm btn-rojo acc" data-a="comprobantes" ' +
              'title="Ver los comprobantes y su PDF">PDF</button>' +
      '<button type="button" class="btn btn-sm btn-gris acc" data-a="deshacer">Cambiar</button>';
  }

  tabla.addEventListener('click', function (ev) {
    var boton = ev.target.closest('.acc');
    if (!boton) return;
    var fila = boton.closest('tr');
    var accion = boton.dataset.a;

    if (accion === 'aceptar') {
      var sug = fila.querySelector('.sug-id');
      enviar(fila, {accion: 'emparejar', producto_id: sug.value}, function (j) { pintarResuelto(fila, j); });

    } else if (accion === 'buscar') {
      buscadorProductos({
        almacenId: function () { return <?= (int) $almacenId ?>; },
        multiple: false,
        onElegir: function (lista) {
          enviar(fila, {accion: 'emparejar', producto_id: lista[0].id}, function (j) { pintarResuelto(fila, j); });
        }
      });

    } else if (accion === 'crear') {
      abrirAlta(fila);

    } else if (accion === 'comprobantes') {
      abrirComprobantes(fila);

    } else if (accion === 'ignorar') {
      enviar(fila, {accion: 'ignorar'}, function (j) { pintarResuelto(fila, j); });

    } else if (accion === 'deshacer') {
      enviar(fila, {accion: 'deshacer'}, function () { location.reload(); });
    }
  });

  // ---- Comprobantes del producto ---------------------------------------
  var modalCpe = document.getElementById('modalCpe');

  function cerrarCpe() { modalCpe.style.display = 'none'; }
  document.getElementById('cpeCerrar').addEventListener('click', cerrarCpe);
  document.getElementById('cpeCerrar2').addEventListener('click', cerrarCpe);
  modalCpe.addEventListener('mousedown', function (e) { if (e.target === modalCpe) cerrarCpe(); });
  document.addEventListener('keydown', function (e) {
    // Sólo cierra esta ventana si el visor del PDF no está encima: si lo está,
    // Escape debe cerrar el PDF y dejar visible la lista de comprobantes.
    if (e.key === 'Escape' && modalCpe.style.display !== 'none' && !window.visorAbierto()) cerrarCpe();
  });

  function esc(t) {
    var d = document.createElement('div');
    d.textContent = t === null || t === undefined ? '' : t;
    return d.innerHTML;
  }
  function num(n, d) {
    return Number(n).toLocaleString('es-PE', {minimumFractionDigits: d, maximumFractionDigits: d});
  }

  function abrirComprobantes(fila) {
    var cuerpo = document.getElementById('cpeCuerpo');
    document.getElementById('cpeTitulo').textContent =
      (fila.dataset.cod ? fila.dataset.cod + ' — ' : '') + fila.dataset.desc;
    document.getElementById('cpeNota').textContent = '';
    cuerpo.innerHTML = '<p class="vacio">Consultando...</p>';
    modalCpe.style.display = 'flex';

    enviar(fila, {
      accion: 'comprobantes',
      periodo: <?= json_encode($filtros['periodo']) ?>,
      tipo_filtro: <?= json_encode($filtros['tipo']) ?>
    }, function (j) {
      var lista = j.comprobantes || [];
      if (!lista.length) { cuerpo.innerHTML = '<p class="vacio">No se encontraron comprobantes.</p>'; return; }

      var conPdf = 0, html =
        '<table class="tabla"><thead><tr>' +
        '<th>Fecha</th><th>Documento</th><th>Contraparte</th>' +
        '<th class="num">Cantidad</th><th class="num">P. unit.</th><th>Archivos</th>' +
        '</tr></thead><tbody>';

      lista.forEach(function (c) {
        if (c.tiene_pdf) conPdf++;
        var arch = c.tiene_pdf
          ? '<button type="button" class="btn btn-sm btn-rojo ver-pdf" data-id="' + c.id +
            '" data-doc="' + esc(c.documento) + '" data-cp="' + esc(c.contraparte) + '">Ver PDF</button>'
          : '<span class="badge badge-warn">sin PDF</span>';
        if (c.tiene_xml) {
          arch += ' <a class="btn btn-sm btn-gris" href="' + window.BASE_URL +
                  'cpe_archivo.php?t=xml&id=' + c.id + '">XML</a>';
        }
        if (c.tiene_cdr) {
          arch += ' <a class="btn btn-sm btn-gris" href="' + window.BASE_URL +
                  'cpe_archivo.php?t=cdr&id=' + c.id + '">CDR</a>';
        }
        html += '<tr>' +
          '<td>' + esc(c.fecha) + '</td>' +
          '<td><strong>' + esc(c.documento) + '</strong><br>' +
              '<small style="color:var(--suave)">' + esc(c.tipo_doc) + '</small></td>' +
          '<td style="white-space:normal;max-width:220px">' + esc(c.contraparte || '—') + '</td>' +
          '<td class="num">' + num(c.cantidad, 2) + '</td>' +
          '<td class="num">' + num(c.valor, 2) + '</td>' +
          '<td><div class="acciones">' + arch + '</div></td>' +
        '</tr>';
      });
      cuerpo.innerHTML = html + '</tbody></table>';

      document.getElementById('cpeNota').textContent =
        lista.length + ' comprobante(s), ' + conPdf + ' con PDF descargado.';
    });
  }

  document.getElementById('cpeCuerpo').addEventListener('click', function (ev) {
    var b = ev.target.closest('.ver-pdf');
    if (!b) return;
    // Si el navegador tiene guardada una versión vieja de app.js, el visor no
    // existe: en vez de no hacer nada, se abre el PDF en una pestaña.
    if (typeof verComprobante !== 'function') {
      window.open(window.BASE_URL + 'cpe_archivo.php?t=pdf&id=' + b.dataset.id, '_blank');
      return;
    }
    verComprobante(b.dataset.id, b.dataset.doc, b.dataset.cp);
  });

  // ---- Formulario de alta de producto ----------------------------------
  var modal = document.getElementById('modalAlta');
  var filaAlta = null;

  function cerrarAlta() {
    modal.style.display = 'none';
    filaAlta = null;
    document.getElementById('altaAviso').textContent = '';
  }

  function abrirAlta(fila) {
    filaAlta = fila;
    var aviso = document.getElementById('altaAviso');
    aviso.textContent = 'Consultando...';
    modal.style.display = 'flex';

    // Los valores propuestos los calcula el servidor: así el código generado y
    // la equivalencia de unidad son los mismos que usaría el alta automática.
    enviar(fila, {
      accion: 'sugerir_alta',
      codigo_sunat: fila.dataset.cod,
      unidad_codigo: fila.dataset.und,
      unidad_nombre: fila.dataset.undn,
      precio: fila.dataset.precio,
      tipo: fila.dataset.tipo
    }, function (j) {
      var v = j.valores;
      document.getElementById('altaCodigo').value = v.codigo;
      document.getElementById('altaDescripcion').value = v.descripcion;
      document.getElementById('altaUnidad').value = v.unidad_id;
      document.getElementById('altaPCompra').value = v.precio_compra;
      document.getElementById('altaPVenta').value = v.precio_venta;
      document.getElementById('altaMinimo').value = v.stock_minimo;
      document.getElementById('altaCategoria').value = '';
      document.getElementById('altaMarca').value = '';

      // Se compara con el código propuesto: si el servidor generó uno, es que
      // el del comprobante no servía como identificador.
      document.getElementById('altaCodigoNota').textContent =
        (fila.dataset.cod && v.codigo.indexOf('SUN-') !== 0)
          ? 'Código del emisor: ' + fila.dataset.cod
          : 'El comprobante no trae un código utilizable' +
            (fila.dataset.cod ? ' (venía "' + fila.dataset.cod + '")' : '') + ': se generó uno.';
      document.getElementById('altaUnidadNota').textContent = fila.dataset.und
        ? 'SUNAT lo declara como ' + fila.dataset.und : '';
      document.getElementById('altaOrigen').textContent =
        'Origen: ' + fila.dataset.tipo + (fila.dataset.ruc ? ' - RUC ' + fila.dataset.ruc : '');

      aviso.textContent = '';
      document.getElementById('altaDescripcion').focus();
    });
  }

  document.getElementById('altaCerrar').addEventListener('click', cerrarAlta);
  document.getElementById('altaCancelar').addEventListener('click', cerrarAlta);
  modal.addEventListener('mousedown', function (e) { if (e.target === modal) cerrarAlta(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') cerrarAlta();
  });

  document.getElementById('altaGuardar').addEventListener('click', function () {
    if (!filaAlta) return;
    if (!document.getElementById('formAlta').reportValidity()) return;

    var fila = filaAlta;
    document.getElementById('altaAviso').textContent = 'Guardando...';

    enviar(fila, {
      accion: 'crear',
      codigo: document.getElementById('altaCodigo').value,
      descripcion_producto: document.getElementById('altaDescripcion').value,
      categoria_id: document.getElementById('altaCategoria').value,
      marca_id: document.getElementById('altaMarca').value,
      unidad_id: document.getElementById('altaUnidad').value,
      precio_compra: document.getElementById('altaPCompra').value,
      precio_venta: document.getElementById('altaPVenta').value,
      stock_minimo: document.getElementById('altaMinimo').value
    }, function (j) {
      cerrarAlta();
      pintarResuelto(fila, j);
    });
  });
})();
</script>
