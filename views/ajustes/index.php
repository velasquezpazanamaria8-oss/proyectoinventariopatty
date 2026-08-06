<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Registrar ajuste de inventario</h2></div>
  <form method="post" class="tarjeta-cuerpo" id="formAjuste">
    <?= Csrf::campo() ?>
    <div class="alerta alerta-warning">
      El ajuste modifica el stock sin un documento de compra o venta. El motivo es obligatorio y queda registrado en el kardex.
    </div>

    <div class="form-grid">
      <div class="campo">
        <label>Fecha *</label>
        <input type="date" name="fecha" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="campo">
        <label>Almacén *</label>
        <select name="almacen_id" id="almacenId" required>
          <?php foreach ($almacenes as $id => $nom): ?><option value="<?= $id ?>"><?= e($nom) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="campo autocomplete">
        <label>Producto *</label>
        <div style="display:flex;gap:6px">
          <input type="text" id="buscarProducto" placeholder="Código o descripción..." autocomplete="off">
          <button type="button" class="btn" id="btnBuscarProducto" style="white-space:nowrap">Buscar</button>
        </div>
        <div class="autocomplete-lista" id="listaProductos"></div>
        <input type="hidden" name="producto_id" id="productoId" required>
        <small id="prodElegido" style="color:var(--suave)"></small>
      </div>
      <div class="campo">
        <label>Tipo de ajuste *</label>
        <select name="tipo" id="tipoAjuste" required>
          <option value="POSITIVO">POSITIVO (suma stock)</option>
          <option value="NEGATIVO">NEGATIVO (resta stock)</option>
        </select>
      </div>
      <div class="campo">
        <label>Cantidad *</label>
        <input type="number" step="0.0001" min="0.0001" name="cantidad" id="cantidad" required>
        <small id="avisoStock" style="color:var(--error)"></small>
      </div>
      <div class="campo">
        <label>Costo unitario (opcional, solo ajuste positivo)</label>
        <input type="number" step="0.0001" min="0" name="costo_unitario" value="0">
      </div>
      <div class="campo" style="grid-column:span 2">
        <label>Motivo *</label>
        <input type="text" name="motivo" required maxlength="255"
               placeholder="Ej.: diferencia por conteo físico, producto dañado, error de digitación...">
      </div>
    </div>

    <div class="acciones" style="margin-top:14px">
      <button class="btn" type="submit">Registrar ajuste</button>
    </div>
  </form>
</div>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Ajustes recientes</h2>
    <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaAjustes','ajustes')">Exportar CSV</button>
  </div>
  <div class="tabla-scroll">
    <?php if (!$ajustes): ?>
      <p class="vacio">No hay ajustes registrados.</p>
    <?php else: ?>
    <table class="tabla" id="tablaAjustes">
      <thead><tr>
        <th>Fecha</th><th>Código</th><th>Producto</th><th>Almacén</th>
        <th>Tipo</th><th class="num">Cantidad</th><th>Motivo</th><th>Usuario</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ajustes as $a): ?>
        <tr>
          <td><?= Vista::fecha($a['fecha']) ?></td>
          <td><?= e($a['codigo']) ?></td>
          <td><?= e($a['descripcion']) ?></td>
          <td><?= e($a['almacen']) ?></td>
          <td><span class="badge <?= $a['tipo'] === 'POSITIVO' ? 'badge-ok' : 'badge-error' ?>"><?= e($a['tipo']) ?></span></td>
          <td class="num"><?= Vista::num($a['cantidad']) ?></td>
          <td><?= e($a['motivo']) ?></td>
          <td><?= e($a['usuario']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var stockActual = 0;

  function almacenActual() { return document.getElementById('almacenId').value; }

  function elegirProducto(p) {
    document.getElementById('productoId').value = p.id;
    stockActual = parseFloat(p.stock) || 0;
    document.getElementById('prodElegido').textContent =
      p.codigo + ' — ' + p.descripcion + ' (stock: ' + fmt(p.stock) + ' ' + p.unidad + ')';
    document.getElementById('cantidad').focus();
  }

  autocompletarProducto({
    input: document.getElementById('buscarProducto'),
    lista: document.getElementById('listaProductos'),
    almacenId: almacenActual,
    onElegir: elegirProducto
  });

  // El ajuste es de un solo producto: la ventana se cierra al elegirlo.
  document.getElementById('btnBuscarProducto').addEventListener('click', function () {
    buscadorProductos({
      almacenId: almacenActual,
      multiple: false,
      onElegir: function (lista) { elegirProducto(lista[0]); }
    });
  });

  function validar() {
    var neg  = document.getElementById('tipoAjuste').value === 'NEGATIVO';
    var cant = parseFloat(document.getElementById('cantidad').value) || 0;
    var av   = document.getElementById('avisoStock');
    av.textContent = (neg && cant > stockActual)
      ? 'El ajuste negativo excede el stock disponible (' + fmt(stockActual) + ').' : '';
    return !av.textContent;
  }

  document.getElementById('cantidad').addEventListener('input', validar);
  document.getElementById('tipoAjuste').addEventListener('change', validar);

  document.getElementById('formAjuste').addEventListener('submit', function (e) {
    if (!document.getElementById('productoId').value) {
      e.preventDefault(); alert('Debe seleccionar un producto.'); return;
    }
    if (!validar()) { e.preventDefault(); alert('Corrija la cantidad del ajuste.'); }
  });
})();
</script>
