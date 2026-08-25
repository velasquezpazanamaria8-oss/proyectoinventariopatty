<?php
// Las columnas activas van primero y en su orden; las apagadas, al final.
$activas = array_column($cfg['columnas'], null, 'campo');
$orden   = array_keys($activas);
foreach (array_keys($campos) as $c) {
    if (!isset($activas[$c])) $orden[] = $c;
}
?>

<div class="disenador">
<form method="post" enctype="multipart/form-data" id="formDiseno" class="disenador-campos"
      data-previa="<?= url('cotizacion_previa.php') ?>#zoom=page-width&toolbar=0&navpanes=0">
  <?= Csrf::campo() ?>

  <div class="tarjeta">
    <div class="tarjeta-cab">
      <h2>Diseño de la cotización — <?= e($empresa['razon_social']) ?></h2>
      <button class="btn" type="submit">Guardar diseño</button>
    </div>
    <div class="tarjeta-cuerpo">
      <div class="alerta alerta-info">
        Esto define cómo se ve el PDF de <strong>esta empresa</strong>. Cada una guarda el suyo,
        así que dos cotizaciones del grupo no tienen por qué parecerse. Los datos fiscales
        —razón social, RUC, dirección— salen de la ficha de la empresa, no de aquí.
      </div>
    </div>
  </div>

  <div class="tarjeta">
    <div class="tarjeta-cab"><h2>Identidad</h2></div>
    <div class="tarjeta-cuerpo">
      <div class="form-grid">
        <div class="campo">
          <label>Logo</label>
          <input type="file" name="logo" accept="image/jpeg,image/png,image/gif,image/webp">
          <small style="color:var(--suave)">
            <?php if (!empty($cfg['logo_ruta'])): ?>
              Ya hay uno cargado. Suba otro para reemplazarlo.
            <?php else: ?>
              JPG o PNG. Se convierte a JPG sobre fondo blanco para el PDF.
            <?php endif; ?>
          </small>
          <?php if (!empty($cfg['logo_ruta'])): ?>
            <img src="<?= url('cotizacion_diseno.php?a=logo&v=' . urlencode((string) ($cfg['actualizado_en'] ?? ''))) ?>"
                 alt="Logo" style="margin-top:8px;max-width:170px;max-height:80px;
                 border:1px solid var(--linea);border-radius:6px;padding:4px;background:#fff">
          <?php endif; ?>
        </div>

        <div class="campo">
          <label>Posición del logo</label>
          <select name="logo_posicion">
            <?php foreach (['IZQUIERDA' => 'Izquierda', 'CENTRO' => 'Centro', 'DERECHA' => 'Derecha'] as $v => $t): ?>
              <option value="<?= $v ?>" <?= $cfg['logo_posicion'] === $v ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="campo">
          <label>Color</label>
          <input type="color" name="color" value="<?= e($cfg['color']) ?>" style="height:38px;padding:3px">
          <small style="color:var(--suave)">Franja de cabecera y títulos de la tabla.</small>
        </div>

        <div class="campo">
          <label>Título del documento</label>
          <input type="text" name="titulo" maxlength="60" value="<?= e($cfg['titulo']) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="tarjeta">
    <div class="tarjeta-cab"><h2>Numeración y cabecera</h2></div>
    <div class="tarjeta-cuerpo">
      <div class="form-grid">
        <div class="campo">
          <label>Prefijo del número</label>
          <input type="text" name="prefijo" maxlength="20" value="<?= e($cfg['prefijo'] ?? '') ?>"
                 placeholder="SUMI-, CP-, o vacío">
        </div>
        <div class="campo">
          <label>Dígitos</label>
          <input type="number" name="digitos" min="1" max="8" value="<?= (int) $cfg['digitos'] ?>">
          <small style="color:var(--suave)">
            Quedaría: <strong><?= e(CotizacionConfig::formatoNumero($cfg, 25)) ?></strong>
          </small>
        </div>
        <div class="campo" style="grid-column:span 2">
          <label>Texto de la referencia al requerimiento</label>
          <input type="text" name="etiqueta_ref" maxlength="60" value="<?= e($cfg['etiqueta_ref']) ?>"
                 placeholder="SEGÚN REQUERIMIENTO / REF. REQ. COT. / …">
        </div>
      </div>

      <div class="form-grid" style="margin-top:14px">
        <label class="campo" style="flex-direction:row;align-items:center;gap:8px">
          <input type="checkbox" name="emisor_etiquetas" value="1" style="width:auto"
                 <?= $cfg['emisor_etiquetas'] ? 'checked' : '' ?>>
          <span>Poner «EMPRESA:», «RUC:» delante de los datos</span>
        </label>
        <label class="campo" style="flex-direction:row;align-items:center;gap:8px">
          <input type="checkbox" name="emisor_derecha" value="1" style="width:auto"
                 <?= $cfg['emisor_derecha'] ? 'checked' : '' ?>>
          <span>Datos del emisor a la derecha</span>
        </label>
        <label class="campo" style="flex-direction:row;align-items:center;gap:8px">
          <input type="checkbox" name="mostrar_telefono" value="1" style="width:auto"
                 <?= $cfg['mostrar_telefono'] ? 'checked' : '' ?>>
          <span>Mostrar el teléfono</span>
        </label>
        <label class="campo" style="flex-direction:row;align-items:center;gap:8px">
          <input type="checkbox" name="mostrar_fecha" value="1" style="width:auto"
                 <?= $cfg['mostrar_fecha'] ? 'checked' : '' ?>>
          <span>Mostrar la fecha</span>
        </label>
      </div>
    </div>
  </div>

  <div class="tarjeta">
    <div class="tarjeta-cab">
      <h2>Columnas de la tabla</h2>
      <span class="modal-conteo">Use ↑ ↓ para cambiar el orden</span>
    </div>
    <div class="tarjeta-cuerpo">
      <p style="color:var(--suave);margin-top:0;font-size:12.5px">
        Elija cuáles aparecen, en qué orden y con qué nombre.
        <strong>Descripción</strong> e <strong>Importe</strong> no se pueden quitar: sin la una no
        se sabe qué se cotiza y sin el otro no hay total.
      </p>
      <table class="tabla" id="tablaColumnas">
        <thead><tr>
          <th style="width:70px">Incluir</th><th style="width:150px">Campo</th>
          <th>Cómo se llama en el PDF</th><th class="num" style="width:110px">Ancho</th>
          <th style="width:80px">Orden</th>
        </tr></thead>
        <tbody>
        <?php foreach ($orden as $i => $campo):
          $act = $activas[$campo] ?? null;
          $fijo = in_array($campo, ['descripcion', 'importe'], true); ?>
          <tr>
            <td>
              <input type="hidden" name="col_campo[<?= $i ?>]" value="<?= e($campo) ?>">
              <input type="checkbox" name="col_activa[<?= $i ?>]" value="1" style="width:auto"
                     <?= $act || $fijo ? 'checked' : '' ?> <?= $fijo ? 'onclick="return false"' : '' ?>>
            </td>
            <td><?= e($campos[$campo]) ?><?= $fijo ? ' <small style="color:var(--suave)">(fija)</small>' : '' ?></td>
            <td><input type="text" name="col_titulo[<?= $i ?>]" maxlength="40"
                       value="<?= e($act['titulo'] ?? $campos[$campo]) ?>"></td>
            <td class="num"><input type="number" name="col_ancho[<?= $i ?>]" min="4" max="70"
                       style="text-align:right" value="<?= (int) ($act['ancho'] ?? 10) ?>"></td>
            <td>
              <button type="button" class="btn btn-sm btn-gris subir" title="Subir">↑</button>
              <button type="button" class="btn btn-sm btn-gris bajar" title="Bajar">↓</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tarjeta">
    <div class="tarjeta-cab"><h2>Textos del pie</h2></div>
    <div class="tarjeta-cuerpo">
      <div class="campo">
        <label>Términos y condiciones</label>
        <textarea name="condiciones" rows="7"><?= e($cfg['condiciones'] ?? '') ?></textarea>
        <small style="color:var(--suave)">Una línea por condición, tal como debe salir impresa.</small>
      </div>
      <div class="campo" style="margin-top:12px">
        <label>Notas («tener en cuenta»)</label>
        <textarea name="notas" rows="3"><?= e($cfg['notas'] ?? '') ?></textarea>
      </div>
      <div class="form-grid" style="margin-top:12px">
        <div class="campo">
          <label>Firma izquierda</label>
          <input type="text" name="firma_izq" maxlength="80" value="<?= e($cfg['firma_izq'] ?? '') ?>"
                 placeholder="<?= e($empresa['razon_social']) ?>">
        </div>
        <div class="campo">
          <label>Firma derecha</label>
          <input type="text" name="firma_der" maxlength="80" value="<?= e($cfg['firma_der'] ?? '') ?>"
                 placeholder="CLIENTE">
        </div>
        <label class="campo" style="flex-direction:row;align-items:center;gap:8px">
          <input type="checkbox" name="incluye_igv" value="1" style="width:auto"
                 <?= $cfg['incluye_igv'] ? 'checked' : '' ?>>
          <span>Los precios se cotizan con IGV incluido</span>
        </label>
      </div>
      <div class="acciones" style="margin-top:14px">
        <button class="btn" type="submit">Guardar diseño</button>
      </div>
    </div>
  </div>
</form>

  <aside class="disenador-previa">
    <div class="tarjeta previa-caja">
      <div class="tarjeta-cab">
        <h2>Vista previa</h2>
        <span class="previa-estado" id="previaEstado">al día</span>
      </div>
      <iframe name="marcoPrevia" id="marcoPrevia" title="Vista previa de la cotización"></iframe>
      <p class="previa-nota">
        Es una cotización de ejemplo con los datos de <?= e($empresa['razon_social']) ?>.
        Cambie cualquier cosa a la izquierda y aquí se vuelve a dibujar el PDF de verdad,
        el mismo que se le manda al cliente. Un logo recién elegido sólo aparece
        después de guardar.
      </p>
    </div>
  </aside>
</div>

<script>
(function () {
  // Reordenar columnas: el orden de las filas es el orden en el PDF.
  var cuerpo = document.querySelector('#tablaColumnas tbody');
  if (!cuerpo) return;
  cuerpo.addEventListener('click', function (ev) {
    var b = ev.target.closest('.subir, .bajar');
    if (!b) return;
    var fila = b.closest('tr');
    if (b.classList.contains('subir') && fila.previousElementSibling) {
      cuerpo.insertBefore(fila, fila.previousElementSibling);
    } else if (b.classList.contains('bajar') && fila.nextElementSibling) {
      cuerpo.insertBefore(fila.nextElementSibling, fila);
    }
    renumerar();
  });

  // Los índices deciden el orden al guardar, así que se reescriben tras mover.
  function renumerar() {
    [...cuerpo.rows].forEach(function (fila, i) {
      fila.querySelectorAll('input').forEach(function (inp) {
        inp.name = inp.name.replace(/\[\d+\]/, '[' + i + ']');
      });
    });
    document.dispatchEvent(new Event('diseno:cambio'));
  }
})();

(function () {
  // Vista previa: se manda el formulario TAL COMO ESTÁ a un generador que no
  // guarda nada, y el PDF que vuelve es el mismo motor que emite el definitivo.
  // Así lo que se ve al diseñar no es una imitación en HTML que con el tiempo
  // se separaría del documento real.
  var form   = document.getElementById('formDiseno');
  var marco  = document.getElementById('marcoPrevia');
  var estado = document.getElementById('previaEstado');
  if (!form || !marco) return;

  var espera = null;

  function dibujar() {
    estado.textContent = 'dibujando…';
    estado.className = 'previa-estado trabajando';

    // Un formulario aparte: el de verdad no se toca, y así el archivo del logo
    // —que aún no está en el servidor— se queda fuera sin estorbar.
    var envio = document.createElement('form');
    envio.method = 'post';
    envio.action = form.dataset.previa;
    envio.target = 'marcoPrevia';
    envio.style.display = 'none';

    new FormData(form).forEach(function (valor, nombre) {
      if (valor instanceof File) return;
      var campo = document.createElement('input');
      campo.type = 'hidden';
      campo.name = nombre;
      campo.value = valor;
      envio.appendChild(campo);
    });

    document.body.appendChild(envio);
    envio.submit();
    document.body.removeChild(envio);
  }

  marco.addEventListener('load', function () {
    estado.textContent = 'al día';
    estado.className = 'previa-estado';
  });

  // Con retardo: escribir un título letra a letra no puede disparar un PDF por
  // pulsación.
  function programar() {
    clearTimeout(espera);
    estado.textContent = 'pendiente…';
    estado.className = 'previa-estado trabajando';
    espera = setTimeout(dibujar, 500);
  }

  form.addEventListener('input', programar);
  form.addEventListener('change', programar);
  document.addEventListener('diseno:cambio', programar);

  dibujar();
})();
</script>
