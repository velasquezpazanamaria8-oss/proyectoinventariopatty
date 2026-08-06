<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Importar productos desde Excel o CSV</h2>
    <div class="acciones">
      <a class="btn btn-sm btn-verde" href="<?= url('productos_importar.php?a=plantilla') ?>">Descargar plantilla</a>
      <a class="btn btn-sm btn-gris" href="<?= url('productos.php') ?>">Volver a productos</a>
    </div>
  </div>

  <form method="post" enctype="multipart/form-data" class="tarjeta-cuerpo">
    <?= Csrf::campo() ?>
    <input type="hidden" name="op" value="analizar">

    <div class="alerta alerta-info">
      La primera fila del archivo debe contener los nombres de las columnas.
      Se reconocen <strong>Código</strong> y <strong>Descripción</strong> (obligatorias) y, opcionalmente,
      Categoría, Marca, Unidad, Precio Compra, Precio Venta, Stock Mínimo, Stock Inicial y Estado.
      No importa el orden ni las mayúsculas o tildes.
      Lo más simple es partir de la plantilla.
      <br><br>
      Si usa <strong>CSV</strong>, escriba los decimales con coma o con punto (<code>45,90</code> o <code>45.90</code>)
      pero <strong>sin separador de miles</strong>: <code>1000</code>, no <code>1.000</code>.
      En archivos .xlsx esto no aplica, los números llegan tal cual de Excel.
    </div>

    <div class="form-grid">
      <div class="campo" style="grid-column:span 2">
        <label>Archivo (.xlsx o .csv, máximo 8 MB y <?= ImportadorProductos::MAX_FILAS ?> filas) *</label>
        <input type="file" name="archivo" accept=".xlsx,.xlsm,.csv,.txt" required>
      </div>
      <div class="campo">
        <label>Almacén para el stock inicial</label>
        <select name="almacen_id">
          <?php foreach ($almacenes as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (int) $opciones['almacen_id'] === $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Opciones</label>
        <label style="font-weight:400;display:flex;gap:7px;align-items:center">
          <input type="checkbox" name="crear_faltantes" value="1" style="width:auto"
                 <?= $opciones['crear_faltantes'] ? 'checked' : '' ?>>
          Crear categorías, marcas y unidades que no existan
        </label>
        <label style="font-weight:400;display:flex;gap:7px;align-items:center">
          <input type="checkbox" name="actualizar_existentes" value="1" style="width:auto"
                 <?= $opciones['actualizar_existentes'] ? 'checked' : '' ?>>
          Actualizar los productos cuyo código ya exista
        </label>
      </div>
    </div>

    <div class="acciones" style="margin-top:14px">
      <button class="btn" type="submit">Analizar archivo</button>
    </div>
    <p style="color:var(--suave);font-size:12.5px;margin-bottom:0">
      El análisis no modifica nada: primero verá el resultado y recién entonces podrá confirmar.
    </p>
  </form>
</div>

<?php if ($previo): ?>
<?php
  $aplicables = (int) $previo['nuevos'] + (int) $previo['actualizar'];
  $nuevosCat  = array_filter($previo['catalogos_nuevos']);
?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Vista previa</h2></div>
  <div class="tarjeta-cuerpo">
    <div class="kpis">
      <div class="kpi exito"><div class="etiqueta">Se crearán</div><div class="valor"><?= (int) $previo['nuevos'] ?></div></div>
      <div class="kpi"><div class="etiqueta">Se actualizarán</div><div class="valor"><?= (int) $previo['actualizar'] ?></div></div>
      <div class="kpi alerta"><div class="etiqueta">Omitidos (ya existen)</div><div class="valor"><?= (int) $previo['omitidos'] ?></div></div>
      <div class="kpi peligro"><div class="etiqueta">Con errores</div><div class="valor"><?= (int) $previo['errores'] ?></div></div>
    </div>

    <?php if ($previo['errores'] > 0): ?>
      <div class="alerta alerta-warning">
        Las filas con error <strong>no se importarán</strong>; el resto sí.
        Corrija el archivo y vuelva a subirlo si necesita incluirlas.
      </div>
    <?php endif; ?>

    <?php if ($previo['omitidos'] > 0): ?>
      <div class="alerta alerta-info">
        Hay <?= (int) $previo['omitidos'] ?> producto(s) cuyo código ya existe. Para sobrescribirlos,
        marque <em>“Actualizar los productos cuyo código ya exista”</em> y analice de nuevo.
      </div>
    <?php endif; ?>

    <?php if ($nuevosCat): ?>
      <div class="alerta alerta-info">
        Se crearán estos registros de catálogo:
        <?php foreach ($nuevosCat as $tabla => $valores): ?>
          <br><strong><?= e(ucfirst($tabla)) ?>:</strong> <?= e(implode(', ', $valores)) ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr>
        <th>Fila</th><th>Acción</th><th>Código</th><th>Descripción</th>
        <th>Categoría</th><th>Marca</th><th>Und</th>
        <th class="num">P. Compra</th><th class="num">P. Venta</th>
        <th class="num">Mínimo</th><th class="num">Stock inicial</th><th>Observaciones</th>
      </tr></thead>
      <tbody>
      <?php foreach ($previo['filas'] as $f): ?>
        <tr>
          <td><?= (int) $f['fila'] ?></td>
          <td>
            <?php if ($f['accion'] === 'crear'): ?><span class="badge badge-ok">Crear</span>
            <?php elseif ($f['accion'] === 'actualizar'): ?><span class="badge">Actualizar</span>
            <?php elseif ($f['accion'] === 'omitir'): ?><span class="badge badge-warn">Omitir</span>
            <?php else: ?><span class="badge badge-error">Error</span><?php endif; ?>
          </td>
          <td><?= e($f['codigo']) ?></td>
          <td style="white-space:normal;max-width:280px"><?= e($f['descripcion']) ?></td>
          <td><?= e($f['categoria']) ?: '—' ?></td>
          <td><?= e($f['marca']) ?: '—' ?></td>
          <td><?= e($f['unidad']) ?: '—' ?></td>
          <td class="num"><?= Vista::num($f['precio_compra']) ?></td>
          <td class="num"><?= Vista::num($f['precio_venta']) ?></td>
          <td class="num"><?= Vista::num($f['stock_minimo']) ?></td>
          <td class="num"><?= $f['accion'] === 'crear' ? Vista::num($f['stock_ini']) : '—' ?></td>
          <td style="white-space:normal;max-width:320px;color:var(--error);font-size:12.5px">
            <?= $f['errores'] ? e(implode(' ', $f['errores'])) : '' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="tarjeta-cuerpo" style="border-top:1px solid var(--linea)">
    <?php if ($aplicables > 0): ?>
      <form method="post">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="aplicar">
        <input type="hidden" name="crear_faltantes" value="<?= $opciones['crear_faltantes'] ? 1 : 0 ?>">
        <input type="hidden" name="actualizar_existentes" value="<?= $opciones['actualizar_existentes'] ? 1 : 0 ?>">
        <input type="hidden" name="almacen_id" value="<?= (int) $opciones['almacen_id'] ?>">
        <button class="btn btn-verde" type="submit"
                data-confirmar="¿Importar <?= $aplicables ?> producto(s)? Esta acción escribe en la base de datos.">
          Confirmar e importar <?= $aplicables ?> producto(s)
        </button>
      </form>
      <p style="color:var(--suave);font-size:12.5px;margin-bottom:0">
        Si algo falla durante la importación no se guarda nada: la carga es una sola transacción.
      </p>
    <?php else: ?>
      <div class="alerta alerta-error" style="margin:0">
        No hay ninguna fila importable. Revise los errores de la tabla y vuelva a subir el archivo.
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
