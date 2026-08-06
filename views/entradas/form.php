<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Nueva entrada de almacén</h2>
    <a class="btn btn-sm btn-gris" href="<?= url('entradas.php') ?>">Volver</a>
  </div>

  <form method="post" id="formEntrada" class="tarjeta-cuerpo">
    <?= Csrf::campo() ?>
    <div class="form-grid">
      <div class="campo">
        <label>Fecha *</label>
        <input type="date" name="fecha" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="campo">
        <label>Almacén *</label>
        <select name="almacen_id" id="almacenId" required>
          <?php foreach ($almacenes as $id => $nom): ?>
            <option value="<?= $id ?>"><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Proveedor</label>
        <select name="proveedor_id">
          <option value="">— Sin proveedor —</option>
          <?php foreach ($proveedores as $id => $nom): ?>
            <option value="<?= $id ?>"><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Tipo de documento</label>
        <select name="tipo_documento">
          <option value="">—</option>
          <option>FACTURA</option><option>BOLETA</option><option>GUIA</option><option>NOTA DE INGRESO</option>
        </select>
      </div>
      <div class="campo">
        <label>N° de documento</label>
        <input type="text" name="nro_documento" maxlength="40">
      </div>
      <div class="campo" style="grid-column:span 2">
        <label>Observación</label>
        <input type="text" name="observacion" maxlength="255">
      </div>
    </div>

    <h3 style="margin-top:20px">Detalle de productos</h3>
    <div class="filtros" style="align-items:flex-end">
      <div class="campo autocomplete" style="flex:1;max-width:520px">
        <label>Agregar producto (código o descripción)</label>
        <input type="text" id="buscarProducto" placeholder="Escriba al menos 2 caracteres..." autocomplete="off">
        <div class="autocomplete-lista" id="listaProductos"></div>
      </div>
      <button type="button" class="btn" id="btnBuscarProducto">Buscar producto</button>
    </div>

    <div class="tabla-scroll" style="margin-top:14px">
      <table class="tabla" id="tablaDetalle">
        <thead><tr>
          <th>Código</th><th>Producto</th><th>Und</th>
          <th class="num" style="width:130px">Cantidad</th>
          <th class="num" style="width:150px">Costo unitario</th>
          <th class="num">Subtotal</th><th></th>
        </tr></thead>
        <tbody id="detalleBody">
          <tr id="filaVacia"><td colspan="7" class="vacio">Sin productos agregados.</td></tr>
        </tbody>
        <tfoot><tr>
          <th colspan="5" class="num">TOTAL</th>
          <th class="num" id="totalEntrada">0.00</th><th></th>
        </tr></tfoot>
      </table>
    </div>

    <div class="acciones" style="margin-top:16px">
      <button class="btn btn-verde" type="submit" id="btnGuardar">Registrar entrada</button>
      <a class="btn btn-gris" href="<?= url('entradas.php') ?>">Cancelar</a>
    </div>
  </form>
</div>

<script>
(function () {
  var body = document.getElementById('detalleBody');
  var agregados = {};

  function recalcular() {
    var total = 0;
    body.querySelectorAll('tr[data-id]').forEach(function (tr) {
      var cant  = parseFloat(tr.querySelector('.inp-cant').value) || 0;
      var costo = parseFloat(tr.querySelector('.inp-costo').value) || 0;
      var sub   = cant * costo;
      tr.querySelector('.sub').textContent = fmt(sub);
      total += sub;
    });
    document.getElementById('totalEntrada').textContent = fmt(total);
    var vacia = document.getElementById('filaVacia');
    if (vacia) vacia.style.display = body.querySelector('tr[data-id]') ? 'none' : '';
  }

  function agregar(p) {
    if (agregados[p.id]) {
      var inp = body.querySelector('tr[data-id="' + p.id + '"] .inp-cant');
      inp.value = (parseFloat(inp.value) || 0) + 1;
      inp.focus();
      recalcular();
      return;
    }
    agregados[p.id] = true;
    var tr = document.createElement('tr');
    tr.dataset.id = p.id;
    tr.innerHTML =
      '<td>' + p.codigo + '<input type="hidden" name="producto_id[]" value="' + p.id + '"></td>' +
      '<td>' + p.descripcion + '</td>' +
      '<td>' + p.unidad + '</td>' +
      '<td class="num"><input class="inp-cant" type="number" step="0.0001" min="0.0001" name="cantidad[]" value="1" required></td>' +
      '<td class="num"><input class="inp-costo" type="number" step="0.0001" min="0" name="costo_unitario[]" value="' + (parseFloat(p.precio_compra) || 0) + '" required></td>' +
      '<td class="num sub">0.00</td>' +
      '<td><button type="button" class="btn btn-sm btn-rojo quitar">×</button></td>';
    body.appendChild(tr);
    tr.querySelector('.quitar').addEventListener('click', function () {
      delete agregados[p.id]; tr.remove(); recalcular();
    });
    tr.querySelectorAll('input[type=number]').forEach(function (i) {
      i.addEventListener('input', recalcular);
    });
    recalcular();
    tr.querySelector('.inp-cant').select();
  }

  function almacenActual() { return document.getElementById('almacenId').value; }

  autocompletarProducto({
    input: document.getElementById('buscarProducto'),
    lista: document.getElementById('listaProductos'),
    almacenId: almacenActual,
    onElegir: agregar
  });

  // Ventana de búsqueda: permite marcar varios productos a la vez.
  document.getElementById('btnBuscarProducto').addEventListener('click', function () {
    buscadorProductos({
      almacenId: almacenActual,
      multiple: true,
      onElegir: function (lista) { lista.forEach(agregar); }
    });
  });

  document.getElementById('formEntrada').addEventListener('submit', function (e) {
    if (!body.querySelector('tr[data-id]')) {
      e.preventDefault();
      alert('Debe agregar al menos un producto a la entrada.');
    }
  });
})();
</script>
