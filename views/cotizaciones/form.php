<?php
$esNueva = $cot === null;
$incluyeIgv = $esNueva ? (bool) $cfg['incluye_igv'] : (bool) $cot['incluye_igv'];
?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2><?= $esNueva ? 'Nueva cotización' : 'Editar cotización' ?></h2>
    <a class="btn btn-sm btn-gris" href="<?= url('cotizaciones.php') ?>">Volver</a>
  </div>

  <form method="post" id="formCot" class="tarjeta-cuerpo">
    <?= Csrf::campo() ?>
    <input type="hidden" name="id" value="<?= e($cot['id'] ?? '') ?>">

    <div class="form-grid">
      <div class="campo" style="grid-column:span 2">
        <label>Cliente *</label>
        <select name="cliente_id" id="clienteSel">
          <option value="">— Escribir los datos a mano —</option>
          <?php foreach ($clientes as $c): ?>
            <option value="<?= (int) $c['id'] ?>"
                    data-ruc="<?= e($c['ruc']) ?>"
                    <?= (int) ($cot['cliente_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
              <?= e($c['razon_social']) ?><?= $c['ruc'] ? ' — ' . e($c['ruc']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small style="color:var(--suave)">
          Si no está en la lista, elija «escribir a mano» y rellene los datos.
          <a href="<?= url('catalogos.php?t=clientes') ?>">Gestionar clientes</a>
        </small>
      </div>
      <div class="campo"><label>Fecha *</label>
        <input type="date" name="fecha" required value="<?= e($cot['fecha'] ?? date('Y-m-d')) ?>"></div>
      <div class="campo"><label>Válida hasta</label>
        <input type="date" name="valida_hasta" value="<?= e($cot['valida_hasta'] ?? '') ?>"></div>
      <div class="campo">
        <label>N° de cotización</label>
        <input type="number" name="numero" min="1" step="1" value="<?= (int) $numero ?>">
        <small style="color:var(--suave)">
          Se propone el siguiente correlativo; cámbielo sólo si esta empresa
          ya venía numerando distinto (por ejemplo, si viene de un Excel).
          Vista: N° <?= e(CotizacionConfig::formatoNumero($cfg, $numero)) ?>
        </small>
      </div>
    </div>

    <?php // Datos sueltos: sólo hacen falta si no se eligió un cliente fichado. ?>
    <div id="clienteManual" class="form-grid" style="margin-top:12px;display:none">
      <div class="campo" style="grid-column:span 2">
        <label>Nombre o razón social</label>
        <input type="text" name="cliente_nombre" maxlength="180" value="<?= e($cot['cliente_nombre'] ?? '') ?>">
      </div>
      <div class="campo"><label>RUC / DNI</label>
        <input type="text" name="cliente_ruc" maxlength="20" value="<?= e($cot['cliente_ruc'] ?? '') ?>"></div>
      <div class="campo"><label>E-mail</label>
        <input type="email" name="cliente_email" maxlength="150" value="<?= e($cot['cliente_email'] ?? '') ?>"></div>
      <div class="campo" style="grid-column:span 2"><label>Dirección</label>
        <input type="text" name="cliente_direccion" maxlength="255" value="<?= e($cot['cliente_direccion'] ?? '') ?>"></div>
    </div>

    <div class="form-grid" style="margin-top:12px">
      <div class="campo"><label><?= e($cfg['etiqueta_ref'] ?: 'Referencia') ?></label>
        <input type="text" name="referencia" maxlength="120" value="<?= e($cot['referencia'] ?? '') ?>"></div>
      <div class="campo" style="grid-column:span 2"><label>Observación</label>
        <input type="text" name="observacion" maxlength="255" value="<?= e($cot['observacion'] ?? '') ?>"></div>
      <label class="campo" style="flex-direction:row;align-items:center;gap:8px">
        <input type="checkbox" name="incluye_igv" value="1" id="incIgv" style="width:auto" <?= $incluyeIgv ? 'checked' : '' ?>>
        <span>Los precios ya incluyen IGV</span>
      </label>
    </div>

    <h3 style="margin-top:20px">Detalle</h3>
    <div class="filtros" style="align-items:flex-end">
      <div class="campo autocomplete" style="flex:1;max-width:520px">
        <label>Agregar del catálogo (código o descripción)</label>
        <input type="text" id="buscarProducto" placeholder="Escriba al menos 2 caracteres..." autocomplete="off">
        <div class="autocomplete-lista" id="listaProductos"></div>
      </div>
      <button type="button" class="btn btn-gris" id="btnBuscarProducto">Buscar producto</button>
      <button type="button" class="btn" id="btnLibre">Agregar línea libre</button>
    </div>

    <div class="tabla-scroll" style="margin-top:14px">
      <table class="tabla" id="tablaDetalle">
        <thead><tr>
          <th style="width:90px">Unidad</th>
          <th style="width:110px" class="num">Cantidad</th>
          <th>Descripción</th>
          <th style="width:130px" class="num">Precio unit.</th>
          <th style="width:120px" class="num">Importe</th>
          <th style="width:44px"></th>
        </tr></thead>
        <tbody id="detalleBody"></tbody>
      </table>
    </div>

    <div class="form-grid" style="margin-top:16px;max-width:420px;margin-left:auto">
      <div class="campo" style="flex-direction:row;justify-content:space-between">
        <span style="color:var(--suave)">Subtotal</span><strong id="tSubtotal">0.00</strong></div>
      <div class="campo" style="flex-direction:row;justify-content:space-between">
        <span style="color:var(--suave)">IGV (18%)</span><strong id="tIgv">0.00</strong></div>
      <div class="campo" style="flex-direction:row;justify-content:space-between;font-size:16px">
        <span>TOTAL</span><strong id="tTotal">0.00</strong></div>
    </div>

    <div class="acciones" style="margin-top:16px">
      <button class="btn btn-verde" type="submit"><?= $esNueva ? 'Crear cotización' : 'Guardar cambios' ?></button>
      <a class="btn btn-gris" href="<?= url('cotizaciones.php') ?>">Cancelar</a>
    </div>
  </form>
</div>

<script>
(function () {
  var body = document.getElementById('detalleBody');
  var simbolo = <?= json_encode(Empresa::simbolo()) ?>;

  function fmt(n) {
    return (Math.round((parseFloat(n) || 0) * 100) / 100)
      .toLocaleString('es-PE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function agregar(p) {
    // Si la única fila está en blanco —la que se abre al entrar— se reutiliza
    // en vez de dejar una línea vacía que luego bloquea el guardado.
    var filas = body.querySelectorAll('tr');
    if (p.descripcion && filas.length === 1 &&
        !filas[0].querySelector('[name="descripcion[]"]').value.trim()) {
      filas[0].remove();
    }
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input type="text" name="unidad[]" maxlength="20" value="' + (p.unidad || '') + '"></td>' +
      '<td class="num"><input class="cant" type="number" step="0.0001" min="0.0001" style="text-align:right" name="cantidad[]" value="' + (p.cantidad || 1) + '" required></td>' +
      '<td><input type="text" name="descripcion[]" maxlength="400" value="' + (p.descripcion || '').replace(/"/g, '&quot;') + '" required>' +
        '<input type="hidden" name="producto_id[]" value="' + (p.id || '') + '">' +
        (p.codigo ? '<small style="color:var(--suave)">' + p.codigo + '</small>' : '') + '</td>' +
      '<td class="num"><input class="precio" type="number" step="0.0001" min="0" style="text-align:right" name="precio[]" value="' + (p.precio || 0) + '" required></td>' +
      '<td class="num importe">0.00</td>' +
      '<td><button type="button" class="btn btn-sm btn-rojo quitar">×</button></td>';
    body.appendChild(tr);

    tr.querySelector('.quitar').addEventListener('click', function () { tr.remove(); recalcular(); });
    tr.querySelectorAll('.cant, .precio').forEach(function (i) {
      i.addEventListener('input', function () { marcarSinPrecio(tr); recalcular(); });
    });
    marcarSinPrecio(tr);
    recalcular();
    return tr;
  }

  // Un tercio de los productos vino de compras y no tiene precio de venta: si
  // no se avisa, la cotización sale con esa línea en cero y nadie lo nota hasta
  // que el cliente la recibe.
  function marcarSinPrecio(tr) {
    var inp = tr.querySelector('.precio');
    var vacio = !(parseFloat(inp.value) > 0);
    inp.style.borderColor = vacio ? '#c8372d' : '';
    var aviso = tr.querySelector('.sinprecio');
    if (vacio && !aviso) {
      aviso = document.createElement('div');
      aviso.className = 'sinprecio';
      aviso.style.cssText = 'color:#c8372d;font-size:11px';
      aviso.textContent = 'falta el precio';
      inp.parentNode.appendChild(aviso);
    } else if (!vacio && aviso) {
      aviso.remove();
    }
  }

  // El importe de cada línea y el reparto del IGV se recalculan en vivo: así el
  // usuario ve el mismo total que saldrá impreso, sin tener que guardar.
  function recalcular() {
    var suma = 0;
    body.querySelectorAll('tr').forEach(function (tr) {
      var c = parseFloat(tr.querySelector('.cant').value) || 0;
      var p = parseFloat(tr.querySelector('.precio').value) || 0;
      var imp = Math.round(c * p * 100) / 100;
      tr.querySelector('.importe').textContent = fmt(imp);
      suma += imp;
    });
    var conIgv = document.getElementById('incIgv').checked;
    var total = Math.round(suma * 100) / 100;
    var sub   = conIgv ? Math.round(total / 1.18 * 100) / 100 : total;
    var igv   = conIgv ? Math.round((total - sub) * 100) / 100 : Math.round(sub * 0.18 * 100) / 100;
    if (!conIgv) total = Math.round((sub + igv) * 100) / 100;

    document.getElementById('tSubtotal').textContent = simbolo + ' ' + fmt(sub);
    document.getElementById('tIgv').textContent      = simbolo + ' ' + fmt(igv);
    document.getElementById('tTotal').textContent    = simbolo + ' ' + fmt(total);
  }

  document.getElementById('incIgv').addEventListener('change', recalcular);

  document.getElementById('btnLibre').addEventListener('click', function () {
    var tr = agregar({});
    tr.querySelector('input[name="descripcion[]"]').focus();
  });

  var almacen = <?= (int) $almacenId ?>;

  autocompletarProducto({
    input: document.getElementById('buscarProducto'),
    lista: document.getElementById('listaProductos'),
    almacenId: function () { return almacen; },
    onElegir: function (p) {
      agregar({id: p.id, codigo: p.codigo, descripcion: p.descripcion,
               unidad: p.unidad, precio: p.precio_venta || 0});
    }
  });

  document.getElementById('btnBuscarProducto').addEventListener('click', function () {
    buscadorProductos({
      almacenId: function () { return almacen; },
      multiple: true,
      onElegir: function (lista) {
        lista.forEach(function (p) {
          agregar({id: p.id, codigo: p.codigo, descripcion: p.descripcion,
                   unidad: p.unidad, precio: p.precio_venta || 0});
        });
      }
    });
  });

  document.getElementById('formCot').addEventListener('submit', function (e) {
    if (!body.querySelector('tr')) {
      e.preventDefault();
      alert('Agregue al menos una línea a la cotización.');
      return;
    }
    var sinPrecio = [...body.querySelectorAll('tr')].filter(function (tr) {
      return !(parseFloat(tr.querySelector('.precio').value) > 0);
    }).length;
    if (sinPrecio && !confirm(sinPrecio + ' línea(s) van con precio 0. ¿Guardar así?')) {
      e.preventDefault();
    }
  });

  // Los datos sueltos sólo se piden cuando no se eligió un cliente de la lista.
  var sel = document.getElementById('clienteSel');
  function alternarCliente() {
    document.getElementById('clienteManual').style.display = sel.value ? 'none' : '';
  }
  sel.addEventListener('change', alternarCliente);
  alternarCliente();

  // Al editar, se recuperan las líneas guardadas.
  <?php if (!$esNueva): ?>
  <?= 'var guardadas = ' . json_encode(array_map(fn($d) => [
        'id' => $d['producto_id'], 'codigo' => $d['producto_codigo'],
        'descripcion' => $d['descripcion'], 'unidad' => $d['unidad'],
        'cantidad' => rtrim(rtrim((string) $d['cantidad'], '0'), '.'),
        'precio' => rtrim(rtrim((string) $d['precio_unitario'], '0'), '.'),
      ], $cot['detalle']), JSON_UNESCAPED_UNICODE) . ";\n" ?>
  guardadas.forEach(agregar);
  <?php else: ?>
  agregar({});
  <?php endif; ?>
})();
</script>
