<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Nueva salida de almacén</h2>
    <a class="btn btn-sm btn-gris" href="<?= url('salidas.php') ?>">Volver</a>
  </div>

  <form method="post" id="formSalida" class="tarjeta-cuerpo">
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
        <label>Motivo *</label>
        <select name="motivo" required>
          <?php foreach (Salida::MOTIVOS as $m): ?><option><?= e($m) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Destino / área / cliente</label>
        <input type="text" name="destino" maxlength="180">
      </div>
      <div class="campo" style="grid-column:span 2">
        <label>Observación</label>
        <input type="text" name="observacion" maxlength="255">
      </div>
    </div>

    <h3 style="margin-top:20px">Detalle de productos</h3>
    <div class="alerta alerta-info">
      No se permite registrar salidas por encima del stock disponible. La valorización se toma del costo promedio.
    </div>

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
          <th class="num">Stock disp.</th>
          <th class="num" style="width:130px">Cantidad</th><th></th>
        </tr></thead>
        <tbody id="detalleBody">
          <tr id="filaVacia"><td colspan="6" class="vacio">Sin productos agregados.</td></tr>
        </tbody>
      </table>
    </div>

    <div class="acciones" style="margin-top:16px">
      <button class="btn btn-naranja" type="submit">Registrar salida</button>
      <a class="btn btn-gris" href="<?= url('salidas.php') ?>">Cancelar</a>
    </div>
  </form>
</div>

<script>
(function () {
  var body = document.getElementById('detalleBody');
  var agregados = {};

  function revisarFila(tr) {
    var stock = parseFloat(tr.dataset.stock) || 0;
    var inp   = tr.querySelector('.inp-cant');
    var cant  = parseFloat(inp.value) || 0;
    var aviso = tr.querySelector('.aviso');
    if (cant > stock) {
      inp.style.borderColor = '#c8372d';
      aviso.textContent = 'Excede el stock';
      return false;
    }
    inp.style.borderColor = '';
    aviso.textContent = '';
    return true;
  }

  function agregar(p) {
    if (agregados[p.id]) { body.querySelector('tr[data-id="' + p.id + '"] .inp-cant').focus(); return; }
    if (parseFloat(p.stock) <= 0) { alert('El producto "' + p.descripcion + '" no tiene stock disponible en este almacén.'); return; }

    agregados[p.id] = true;
    var tr = document.createElement('tr');
    tr.dataset.id = p.id;
    tr.dataset.stock = p.stock;
    tr.innerHTML =
      '<td>' + p.codigo + '<input type="hidden" name="producto_id[]" value="' + p.id + '"></td>' +
      '<td>' + p.descripcion + '</td>' +
      '<td>' + p.unidad + '</td>' +
      '<td class="num">' + fmt(p.stock) + '</td>' +
      '<td class="num"><input class="inp-cant" type="number" step="0.0001" min="0.0001" max="' + p.stock + '" name="cantidad[]" value="1" required>' +
      '<div class="aviso" style="color:#c8372d;font-size:11.5px"></div></td>' +
      '<td><button type="button" class="btn btn-sm btn-rojo quitar">×</button></td>';
    body.appendChild(tr);
    document.getElementById('filaVacia').style.display = 'none';

    tr.querySelector('.quitar').addEventListener('click', function () {
      delete agregados[p.id];
      tr.remove();
      if (!body.querySelector('tr[data-id]')) document.getElementById('filaVacia').style.display = '';
    });
    tr.querySelector('.inp-cant').addEventListener('input', function () { revisarFila(tr); });
    tr.querySelector('.inp-cant').select();
  }

  function almacenActual() { return document.getElementById('almacenId').value; }

  autocompletarProducto({
    input: document.getElementById('buscarProducto'),
    lista: document.getElementById('listaProductos'),
    almacenId: almacenActual,
    onElegir: agregar
  });

  // En salidas la ventana no deja marcar productos sin stock disponible.
  document.getElementById('btnBuscarProducto').addEventListener('click', function () {
    buscadorProductos({
      almacenId: almacenActual,
      multiple: true,
      exigirStock: true,
      onElegir: function (lista) { lista.forEach(agregar); }
    });
  });

  // Al cambiar de almacén el stock mostrado deja de ser válido.
  document.getElementById('almacenId').addEventListener('change', function () {
    if (body.querySelector('tr[data-id]') &&
        confirm('Cambiar de almacén vaciará el detalle. ¿Continuar?')) {
      body.querySelectorAll('tr[data-id]').forEach(function (tr) { tr.remove(); });
      agregados = {};
      document.getElementById('filaVacia').style.display = '';
    }
  });

  document.getElementById('formSalida').addEventListener('submit', function (e) {
    var filas = body.querySelectorAll('tr[data-id]');
    if (!filas.length) { e.preventDefault(); alert('Debe agregar al menos un producto.'); return; }
    var ok = true;
    filas.forEach(function (tr) { if (!revisarFila(tr)) ok = false; });
    if (!ok) { e.preventDefault(); alert('Hay cantidades que exceden el stock disponible.'); }
  });
})();
</script>
