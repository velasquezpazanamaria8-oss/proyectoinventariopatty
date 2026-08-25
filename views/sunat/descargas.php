<?php $fmt = fn(string $p): string => substr($p, 4, 2) . '/' . substr($p, 0, 4); ?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Descargar comprobantes — <?= e($fmt($periodo)) ?></h2>
    <form method="get" style="display:flex;gap:6px;align-items:center">
      <select name="periodo" onchange="this.form.submit()">
        <?php foreach ($periodos as $p => $s): ?>
          <option value="<?= e($p) ?>" <?= (string) $p === (string) $periodo ? 'selected' : '' ?>><?= e($fmt($p)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <div class="tarjeta-cuerpo">
    <div class="alerta alerta-info">
      Aquí se baja de SUNAT el <strong>XML y el PDF</strong> de cada comprobante y se extraen sus
      <strong>líneas de producto</strong>, que es lo que el SIRE no entrega y el inventario necesita.
      Los archivos quedan guardados en el servidor. <strong>Todavía no se toca el stock.</strong>
    </div>

    <div class="kpis">
      <div class="kpi"><div class="etiqueta">Comprobantes</div><div class="valor" id="kTotal"><?= (int) $avance['total'] ?></div></div>
      <div class="kpi exito"><div class="etiqueta">Descargados</div><div class="valor" id="kOk"><?= (int) $avance['ok'] ?></div></div>
      <div class="kpi alerta"><div class="etiqueta">Pendientes</div><div class="valor" id="kPend"><?= (int) $avance['pendientes'] ?></div></div>
      <div class="kpi peligro"><div class="etiqueta">Con error</div><div class="valor" id="kErr"><?= (int) $avance['error'] ?></div></div>
      <div class="kpi"><div class="etiqueta">Líneas obtenidas</div><div class="valor" id="kItems"><?= (int) $avance['items'] ?></div></div>
    </div>

    <div id="barraCaja" style="background:#e2e8f0;border-radius:6px;height:14px;overflow:hidden;margin:6px 0 12px">
      <div id="barra" style="background:var(--acento);height:100%;width:<?= (int) $avance['porcentaje'] ?>%;transition:width .3s"></div>
    </div>

    <div class="acciones">
      <?php // Cuenta lo que de verdad se va a intentar: los pendientes más los
            // que fallaron y aún conservan intentos. ?>
      <button class="btn btn-verde" id="btnDescargar" <?= $avance['por_intentar'] === 0 ? 'disabled' : '' ?>>
        Descargar pendientes (<?= (int) $avance['por_intentar'] ?>)
      </button>
      <button class="btn btn-gris" id="btnParar" style="display:none">Detener</button>
      <span class="modal-conteo" id="estado"></span>
    </div>

    <?php /* Tras 3 intentos fallidos un comprobante deja de reintentarse solo,
             para no machacar a SUNAT. Cuando eso deja el botón de arriba en
             cero pero siguen quedando errores, hay que poder insistir a mano:
             si no, la pantalla da el período por terminado y no ofrece salida. */
    if ($avance['error'] > 0 && $avance['por_intentar'] === 0): ?>
      <form method="post" class="acciones" style="margin-top:10px">
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="op" value="reintentar">
        <?php $nErr = (int) $avance['error']; ?>
        <button class="btn btn-naranja"
                data-confirmar="Se volverá a intentar la descarga de <?= $nErr ?> comprobante<?= $nErr === 1 ? '' : 's' ?> que ya <?= $nErr === 1 ? 'agotó' : 'agotaron' ?> sus 3 intentos. ¿Continuar?">
          <?= $nErr === 1 ? 'Reintentar el que falló' : 'Reintentar los ' . $nErr . ' que fallaron' ?>
        </button>
      </form>
    <?php endif; ?>

    <p style="color:var(--suave);font-size:12.5px;margin-bottom:0">
      Se procesa por lotes: cada comprobante se guarda al terminarlo, así que puede detenerlo y
      continuar después sin perder lo hecho. SUNAT tarda entre 2 y 50 segundos por comprobante.
      <strong>Puede recargar la página o cerrarla:</strong> la descarga sigue su curso y se retoma
      sola al volver a entrar. Un comprobante que falla se reintenta hasta <strong>3 veces</strong>;
      pasado ese tope deja de intentarse solo y hay que pedirlo con el botón naranja.
    </p>

    <div id="registro" style="margin-top:12px;max-height:180px;overflow:auto;font-size:12px;
         font-family:monospace;color:var(--suave);display:none;
         background:#f8fafc;border:1px solid var(--linea);border-radius:6px;padding:8px"></div>
  </div>
</div>

<?php if ($conErrores): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Comprobantes con error (<?= count($conErrores) ?>)</h2></div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr>
        <th>Origen</th><th>RUC emisor</th><th>Comprobante</th><th>Fecha</th>
        <th class="num">Total</th><th>Contraparte</th><th>Motivo</th>
        <th class="no-export">Si no llega</th>
      </tr></thead>
      <tbody>
      <?php foreach ($conErrores as $c):
        // El emisor es quien lo transmitió a SUNAT: el proveedor en una compra,
        // la propia empresa en una venta. Es el RUC con el que hay que buscarlo.
        $rucEmisor = $c['tipo'] === 'ventas' ? $rucPropio : (string) $c['ruc_contraparte'];
        $tipoDoc   = SunatComprobante::tipoDoc($c['cod_tipo_cdp']);
        $doc       = $c['serie'] . '-' . $c['numero']; ?>
        <tr>
          <td><span class="badge <?= $c['tipo'] === 'ventas' ? 'badge-ok' : '' ?>"><?= e(ucfirst($c['tipo'])) ?></span></td>
          <td><?= e($rucEmisor ?: '—') ?></td>
          <td><strong><?= e($doc) ?></strong><br>
              <small style="color:var(--suave)"><?= e($tipoDoc) ?> (<?= e($c['cod_tipo_cdp'] ?: '—') ?>)</small></td>
          <td><?= Vista::fecha($c['fecha_emision']) ?></td>
          <td class="num"><?= Vista::num($c['total']) ?></td>
          <td style="white-space:normal;max-width:200px"><?= e($c['nombre_contraparte'] ?? '—') ?></td>
          <td style="white-space:normal"><?= e($c['descarga_msg'] ?: 'Sin detalle del último intento.') ?></td>
          <td class="no-export">
            <button type="button" class="btn btn-sm btn-gris subir-xml"
                    data-id="<?= (int) $c['id'] ?>"
                    data-doc="<?= e($doc) ?>"
                    data-serie="<?= e($c['serie']) ?>"
                    data-numero="<?= e($c['numero']) ?>"
                    data-tipo="<?= e($c['tipo']) ?>"
                    data-emisor="<?= e($rucEmisor) ?>"
                    data-tipodoc="<?= e($tipoDoc) ?>"
                    data-cod="<?= e($c['cod_tipo_cdp'] ?: '') ?>"
                    data-fecha="<?= e(Vista::fecha($c['fecha_emision'])) ?>"
                    data-total="<?= e(Vista::num($c['total'])) ?>"
                    data-nombre="<?= e($c['nombre_contraparte'] ?? '') ?>">Subir XML</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="tarjeta-cuerpo">
    <p style="color:var(--suave);font-size:12.5px;margin:0">
      Los errores temporales se reintentan al pulsar «Descargar pendientes», mientras al
      comprobante le queden intentos; agotados los 3, use «Reintentar los que fallaron».
      Un «SUNAT no tiene ese comprobante» suele significar que el emisor aún no lo transmitió.
    </p>
    <p style="color:var(--suave);font-size:12.5px;margin:8px 0 0">
      Si un comprobante no llega por más que insista, consiga el <strong>XML</strong> por su
      cuenta —del portal de SUNAT o pidiéndoselo al proveedor— y súbalo con el botón de su fila.
      De ese archivo salen las líneas de producto, que es lo único que el inventario necesita.
    </p>
  </div>
</div>

<?php endif; ?>

<!-- Carga manual del XML de un comprobante -->
<div class="modal-fondo" id="modalSubir" style="display:none">
  <div class="modal-caja" style="max-width:560px">
    <div class="modal-cab">
      <h2>Subir el XML de <span id="subDoc"></span></h2>
      <button type="button" class="modal-cerrar" id="subCerrar" aria-label="Cerrar">&times;</button>
    </div>

    <form method="post" enctype="multipart/form-data" class="tarjeta-cuerpo">
      <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="op" value="subir_xml">
      <input type="hidden" name="id" id="subId">

      <?php /* Los datos con los que se busca el comprobante en el portal de
               SUNAT. Se muestran juntos para no tener que ir a buscarlos a la
               tabla mientras se rellena el formulario del portal. */ ?>
      <table class="tabla" style="margin-bottom:14px">
        <tbody>
          <tr><th style="width:150px">RUC emisor</th>
              <td><strong id="subRuc"></strong>
                  <button type="button" class="btn btn-sm btn-gris copiar" data-de="subRuc">Copiar</button></td></tr>
          <tr><th>Tipo de comprobante</th><td id="subTipoDoc"></td></tr>
          <?php /* Separados porque el buscador de SUNAT los pide en dos
                   casillas distintas: copiarlos juntos obliga a partirlos. */ ?>
          <tr><th>Serie</th>
              <td><strong id="subSerie"></strong>
                  <button type="button" class="btn btn-sm btn-gris copiar" data-de="subSerie">Copiar</button></td></tr>
          <tr><th>Número</th>
              <td><strong id="subNumero"></strong>
                  <button type="button" class="btn btn-sm btn-gris copiar" data-de="subNumero">Copiar</button></td></tr>
          <tr><th>Fecha de emisión</th><td id="subFecha"></td></tr>
          <tr><th>Importe total</th><td id="subTotal"></td></tr>
        </tbody>
      </table>

      <p style="color:var(--suave);font-size:12.5px" id="subDonde"></p>

      <div class="alerta alerta-info" style="font-size:12.5px">
        Suba el <strong>XML</strong> del comprobante (o el ZIP tal como lo entrega SUNAT).
        Se comprueba que la serie, el número, el tipo y el RUC del emisor coincidan con
        <strong id="subDoc2"></strong>; si no, no se registra nada.
      </div>

      <div class="campo">
        <label>Archivo XML o ZIP</label>
        <input type="file" name="archivo" accept=".xml,.zip,text/xml,application/xml,application/zip" required>
      </div>

      <div class="acciones" style="margin-top:14px;justify-content:flex-end">
        <button type="button" class="btn btn-gris" id="subCancelar">Cancelar</button>
        <button class="btn btn-verde" type="submit">Cargar líneas del XML</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('modalSubir');
  function cerrar() { modal.style.display = 'none'; }

  // Delegación: los botones están en dos tablas distintas, y la de archivos se
  // pinta después de este script. Escuchando en el documento da igual el orden.
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('.subir-xml');
    if (b) {
      document.getElementById('subId').value = b.dataset.id;
      document.getElementById('subDoc').textContent  = b.dataset.doc;
      document.getElementById('subDoc2').textContent = b.dataset.doc;

      document.getElementById('subRuc').textContent     = b.dataset.emisor || '—';
      document.getElementById('subTipoDoc').textContent = b.dataset.tipodoc
        + (b.dataset.cod ? ' — código ' + b.dataset.cod : '');
      document.getElementById('subSerie').textContent   = b.dataset.serie;
      document.getElementById('subNumero').textContent  = b.dataset.numero;
      document.getElementById('subFecha').textContent   = b.dataset.fecha;
      document.getElementById('subTotal').textContent   = b.dataset.total;
      // En compras el XML lo tiene el proveedor; en ventas, la propia empresa.
      document.getElementById('subDonde').textContent = b.dataset.tipo === 'compras'
        ? 'Es una compra: el XML lo emite ' + (b.dataset.nombre || 'el proveedor')
          + (b.dataset.emisor ? ' (RUC ' + b.dataset.emisor + ')' : '') + '. Pídaselo si no lo tiene.'
        : 'Es una venta suya: el XML lo generó su propio sistema de facturación.';
      modal.style.display = 'flex';
    }
  });

  // Copiar al portapapeles para pegarlo en el buscador de SUNAT sin teclearlo.
  modal.addEventListener('click', function (ev) {
    var b = ev.target.closest('.copiar');
    if (!b) return;
    var texto = document.getElementById(b.dataset.de).textContent.trim();
    var avisar = function () {
      var antes = b.textContent;
      b.textContent = 'Copiado';
      setTimeout(function () { b.textContent = antes; }, 1200);
    };
    // El portapapeles moderno sólo existe en HTTPS o en localhost; en una red
    // interna por HTTP hay que recurrir al método antiguo.
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(texto).then(avisar);
      return;
    }
    var t = document.createElement('textarea');
    t.value = texto;
    t.style.position = 'fixed';
    t.style.opacity = '0';
    document.body.appendChild(t);
    t.select();
    try { document.execCommand('copy'); avisar(); } catch (e) { /* sin permiso: se copia a mano */ }
    document.body.removeChild(t);
  });

  document.getElementById('subCerrar').addEventListener('click', cerrar);
  document.getElementById('subCancelar').addEventListener('click', cerrar);
  modal.addEventListener('mousedown', function (e) { if (e.target === modal) cerrar(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display !== 'none') cerrar();
  });
})();
</script>


<?php if ($archivos): ?>
<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Archivos descargados (<?= count($archivos) ?>)</h2>
    <div class="campo" style="margin:0;min-width:220px">
      <input type="text" id="buscarArchivo" placeholder="Filtrar por serie, número o nombre...">
    </div>
  </div>
  <div class="tarjeta-cuerpo">
    <p style="color:var(--suave);margin:0;font-size:12.5px">
      El <strong>PDF</strong> se abre en una pestaña nueva; el <strong>XML</strong> y el
      <strong>CDR</strong> se descargan. Los archivos están guardados en el servidor, fuera del
      alcance web: sólo se sirven a través de esta pantalla y para la empresa activa.
    </p>
  </div>
  <div class="tabla-scroll">
    <table class="tabla" id="tablaArchivos">
      <thead><tr>
        <th>Fecha</th><th>Origen</th><th>Documento</th><th>Contraparte</th>
        <th class="num">Total</th><th class="num">Líneas</th><th>Archivos</th>
      </tr></thead>
      <tbody>
      <?php foreach ($archivos as $a): ?>
        <tr data-buscar="<?= e(mb_strtolower($a['serie'] . '-' . $a['numero'] . ' ' . (string) $a['nombre_contraparte'])) ?>">
          <td><?= Vista::fecha($a['fecha_emision']) ?></td>
          <td><span class="badge <?= $a['tipo'] === 'ventas' ? 'badge-ok' : '' ?>"><?= e(ucfirst($a['tipo'])) ?></span></td>
          <td><strong><?= e($a['serie']) ?>-<?= e($a['numero']) ?></strong><br>
              <small style="color:var(--suave)"><?= e(SunatComprobante::tipoDoc($a['cod_tipo_cdp'])) ?></small></td>
          <td style="white-space:normal;max-width:260px"><?= e($a['nombre_contraparte'] ?? '—') ?></td>
          <td class="num"><?= Vista::num($a['total']) ?></td>
          <td class="num"><?= (int) $a['items_cant'] ?></td>
          <td>
            <div class="acciones">
              <?php $base = 'cpe_archivo.php?id=' . (int) $a['id']; ?>

              <?php if ($a['pdf_path']): ?>
                <button type="button" class="btn btn-sm btn-rojo ver-pdf"
                        data-id="<?= (int) $a['id'] ?>"
                        data-doc="<?= e($a['serie'] . '-' . $a['numero']) ?>"
                        data-cp="<?= e($a['nombre_contraparte'] ?? '') ?>">Ver PDF</button>
              <?php else: ?>
                <span class="badge badge-warn">sin PDF</span>
              <?php endif; ?>

              <?php if ($a['xml_path']): ?>
                <a class="btn btn-sm btn-gris" href="<?= url($base . '&t=xml') ?>">XML</a>
              <?php else:
                // Sin el XML el comprobante funciona —sus líneas vinieron de la
                // metadata— pero no hay documento firmado que enseñar en una
                // revisión. Desde aquí se puede aportar.
                $rucEmisorA = $a['tipo'] === 'ventas' ? $rucPropio : (string) $a['ruc_contraparte']; ?>
                <button type="button" class="btn btn-sm btn-naranja subir-xml"
                        data-id="<?= (int) $a['id'] ?>"
                        data-doc="<?= e($a['serie'] . '-' . $a['numero']) ?>"
                        data-serie="<?= e($a['serie']) ?>"
                        data-numero="<?= e($a['numero']) ?>"
                        data-tipo="<?= e($a['tipo']) ?>"
                        data-emisor="<?= e($rucEmisorA) ?>"
                        data-tipodoc="<?= e(SunatComprobante::tipoDoc($a['cod_tipo_cdp'])) ?>"
                        data-cod="<?= e($a['cod_tipo_cdp'] ?: '') ?>"
                        data-fecha="<?= e(Vista::fecha($a['fecha_emision'])) ?>"
                        data-total="<?= e(Vista::num($a['total'])) ?>"
                        data-nombre="<?= e($a['nombre_contraparte'] ?? '') ?>">Falta el XML</button>
              <?php endif; ?>

              <?php if ($a['cdr_path']): ?>
                <a class="btn btn-sm btn-gris" href="<?= url($base . '&t=cdr') ?>">CDR</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  // El PDF se ve en una ventana sobre la propia pantalla, sin perder el filtro
  // ni la posición de la tabla.
  var tabla = document.getElementById('tablaArchivos');
  tabla.addEventListener('click', function (ev) {
    var b = ev.target.closest('.ver-pdf');
    if (!b) return;
    // Con una versión vieja de app.js en caché el visor no existe: se abre en
    // una pestaña antes que dejar el botón sin hacer nada.
    if (typeof verComprobante !== 'function') {
      window.open(window.BASE_URL + 'cpe_archivo.php?t=pdf&id=' + b.dataset.id, '_blank');
      return;
    }
    verComprobante(b.dataset.id, b.dataset.doc, b.dataset.cp);
  });

  var filtro = document.getElementById('buscarArchivo');
  if (!filtro) return;
  filtro.addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    document.querySelectorAll('#tablaArchivos tbody tr').forEach(function (tr) {
      tr.style.display = (!q || tr.dataset.buscar.indexOf(q) !== -1) ? '' : 'none';
    });
  });
})();
</script>
<?php endif; ?>

<?php if ($distintos): ?>
<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Productos distintos encontrados (<?= count($distintos) ?>)</h2>
    <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaDist','productos_sunat')">Exportar CSV</button>
  </div>
  <div class="tarjeta-cuerpo">
    <p style="color:var(--suave);margin:0">
      Agrupados por el código del emisor cuando existe, y si no por la descripción.
      <strong>Esta es la lista que hay que emparejar con el catálogo</strong> en el siguiente paso.
    </p>
  </div>
  <div class="tabla-scroll">
    <table class="tabla" id="tablaDist">
      <thead><tr>
        <th>Código</th><th>Descripción</th><th>Und</th><th>Origen</th>
        <th class="num">Veces</th><th class="num">Cantidad total</th>
        <th class="num">Precio mín.</th><th class="num">Precio máx.</th><th>Producto</th>
      </tr></thead>
      <tbody>
      <?php foreach ($distintos as $d): ?>
        <tr>
          <td><?= e($d['codigo_sunat'] ?: '—') ?></td>
          <td style="white-space:normal;max-width:340px"><?= e($d['descripcion']) ?></td>
          <td><?= e($d['unidad_codigo'] ?: '—') ?></td>
          <td><span class="badge <?= $d['tipo'] === 'ventas' ? 'badge-ok' : '' ?>"><?= e(ucfirst($d['tipo'])) ?></span></td>
          <td class="num"><?= (int) $d['veces'] ?></td>
          <td class="num"><?= Vista::num($d['cantidad_total']) ?></td>
          <td class="num"><?= Vista::num($d['precio_min'], 4) ?></td>
          <td class="num"><?= Vista::num($d['precio_max'], 4) ?></td>
          <td><?php if ($d['producto_id']): ?><span class="badge badge-ok">emparejado</span>
              <?php else: ?><span class="badge badge-warn">sin emparejar</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($items): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Últimas líneas descargadas</h2></div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr>
        <th>Fecha</th><th>Comprobante</th><th>Contraparte</th><th>Código</th><th>Descripción</th>
        <th class="num">Cantidad</th><th>Und</th><th class="num">V. unit. sin IGV</th><th class="num">Importe</th>
      </tr></thead>
      <tbody>
      <?php foreach (array_slice($items, 0, 60) as $i): ?>
        <tr>
          <td><?= Vista::fecha($i['fecha_emision']) ?></td>
          <td><span class="badge <?= $i['tipo'] === 'ventas' ? 'badge-ok' : '' ?>"><?= e(substr($i['tipo'], 0, 1)) ?></span>
              <?= e($i['serie']) ?>-<?= e($i['numero']) ?></td>
          <td style="white-space:normal;max-width:180px"><?= e(mb_substr((string) $i['nombre_contraparte'], 0, 28)) ?></td>
          <td><?= e($i['codigo_sunat'] ?: '—') ?></td>
          <td style="white-space:normal;max-width:300px"><?= e($i['descripcion']) ?></td>
          <td class="num"><?= Vista::num($i['cantidad']) ?></td>
          <td><?= e($i['unidad_codigo'] ?: '—') ?></td>
          <td class="num"><?= Vista::num($i['valor_unitario'], 4) ?></td>
          <td class="num"><?= Vista::num($i['importe']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var boton = document.getElementById('btnDescargar');
  var parar = document.getElementById('btnParar');
  var estado = document.getElementById('estado');
  var registro = document.getElementById('registro');
  var seguir = false;
  var esperando = false;    // evita repetir el aviso de "espere" en cada intento

  function pinta(a) {
    document.getElementById('kOk').textContent = a.ok;
    document.getElementById('kPend').textContent = a.pendientes;
    document.getElementById('kErr').textContent = a.error;
    document.getElementById('kItems').textContent = a.items;
    document.getElementById('barra').style.width = a.porcentaje + '%';
  }

  function anota(t) {
    registro.style.display = 'block';
    registro.insertAdjacentHTML('afterbegin', t + '<br>');
  }

  function lote() {
    if (!seguir) return;
    estado.textContent = 'Descargando...';

    fetch(window.BASE_URL + 'api/descargar_cpe.php?periodo=<?= e($periodo) ?>'
          + '&_csrf=' + encodeURIComponent(<?= json_encode(Csrf::token()) ?>))
      .then(function (r) { return r.json().then(function (j) { j._http = r.status; return j; }); })
      .then(function (j) {
        if (j._http === 409) {
          // Hay otro lote en curso: es lo normal justo después de recargar,
          // porque el anterior sigue trabajando en el servidor. No es un error,
          // sólo hay que esperarlo, así que se reintenta en unos segundos.
          if (!esperando) { anota('Esperando a que termine el lote anterior...'); esperando = true; }
          estado.textContent = 'Esperando...';
          setTimeout(lote, 4000);
          return;
        }
        esperando = false;
        if (!j.ok) { anota('ERROR: ' + j.error); terminar(); return; }
        pinta(j.avance);
        (j.hechos || []).forEach(function (h) {
          anota(h.ok
            ? h.ref + ' — ' + h.items + ' línea(s)' + (h.falta ? ' (sin ' + h.falta + ')' : '')
            : h.ref + ' — ' + h.msg);
        });
        if (j.terminado) {
          anota('Listo: no quedan pendientes.');
          terminar();
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          lote();                       // siguiente lote
        }
      })
      .catch(function (e) { anota('Se cortó la conexión: ' + e.message); terminar(); });
  }

  function terminar() {
    seguir = false;
    boton.disabled = false;
    parar.style.display = 'none';
    estado.textContent = '';
  }

  boton.addEventListener('click', function () {
    seguir = true;
    boton.disabled = true;
    parar.style.display = '';
    lote();
  });
  parar.addEventListener('click', function () {
    // Se avisa al servidor de que ya no hay que seguir; el lote que esté en
    // curso termina lo que tenía entre manos y no se pide ninguno más.
    fetch(window.BASE_URL + 'api/descargar_cpe.php?op=parar&periodo=<?= e($periodo) ?>'
          + '&_csrf=' + encodeURIComponent(<?= json_encode(Csrf::token()) ?>));
    anota('Detenido por el usuario.');
    terminar();
  });

  // Si el período seguía bajándose —porque se recargó la página, se cerró la
  // pestaña o se cortó la conexión— se retoma solo, sin preguntar nada.
  <?php if ($enMarcha): ?>
  anota('Continuando la descarga que estaba en curso...');
  boton.click();
  <?php endif; ?>
})();
</script>
