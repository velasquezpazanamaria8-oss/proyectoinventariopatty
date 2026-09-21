<?php
$catalogo = [
    'stock_minimo'  => ['Productos con stock mínimo', 'Productos en o por debajo del mínimo definido.'],
    'valorizado'    => ['Inventario valorizado',      'Existencias por costo promedio ponderado.'],
    'entradas'      => ['Entradas por fecha',         'Ingresos de almacén en un rango de fechas.'],
    'salidas'       => ['Salidas por fecha',          'Egresos de almacén en un rango de fechas.'],
    'por_usuario'   => ['Movimientos por usuario',    'Cantidad de movimientos por responsable.'],
    'por_categoria' => ['Inventario por categoría',   'Existencias y valor agrupados por categoría.'],
    'por_almacen'   => ['Inventario por almacén',     'Existencias y valor agrupados por almacén.'],
    'compras_producto' => ['Compras por producto', 'Qué se compró y cuánto, por producto, en un mes o un día.'],
    'ventas_producto'  => ['Ventas por producto',  'Qué se vendió y cuánto, por producto, en un mes o un día.'],
];
?>

<?php if (!$reporte): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Reportes disponibles</h2></div>
  <div class="tarjeta-cuerpo">
    <div class="kpis">
      <?php foreach ($catalogo as $clave => $info): ?>
        <?php if ($clave === 'valorizado' && !Auth::puede('reportes.valorizado')) continue; ?>
        <a class="kpi" href="<?= url('reportes.php?r=' . $clave) ?>" style="text-decoration:none;color:inherit">
          <div class="etiqueta">Reporte</div>
          <div style="font-size:16px;font-weight:600;margin:4px 0"><?= e($info[0]) ?></div>
          <div style="color:var(--suave);font-size:12.5px"><?= e($info[1]) ?></div>
        </a>
      <?php endforeach; ?>
      <a class="kpi" href="<?= url('kardex.php') ?>" style="text-decoration:none;color:inherit">
        <div class="etiqueta">Reporte</div>
        <div style="font-size:16px;font-weight:600;margin:4px 0">Kardex general</div>
        <div style="color:var(--suave);font-size:12.5px">Historial completo de movimientos.</div>
      </a>
    </div>
    <p style="color:var(--suave)">
      Todos los reportes se exportan a CSV (abrible en Excel) y se pueden imprimir o guardar como PDF
      desde el diálogo de impresión del navegador.
    </p>
  </div>
</div>

<?php else: ?>
<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2><?= e($catalogo[$reporte][0] ?? 'Reporte') ?></h2>
    <?php
      // Los filtros vigentes se arrastran al exportador para que el archivo
      // contenga exactamente lo que se ve en pantalla.
      $qsExport = http_build_query(array_filter([
          'r'          => $reporte,
          'desde'      => $desde,
          'hasta'      => $hasta,
          'almacen_id' => $almacen,
          'codigo'     => $codigo ?? '',
      ]));
    ?>
    <div class="acciones">
      <a class="btn btn-sm btn-rojo" href="<?= url('exportar.php?f=pdf&' . $qsExport) ?>">Descargar PDF</a>
      <a class="btn btn-sm btn-verde" href="<?= url('exportar.php?f=xlsx&' . $qsExport) ?>">Descargar Excel</a>
      <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaReporte','<?= e($reporte) ?>')">CSV</button>
      <button class="btn btn-sm btn-gris" onclick="window.print()">Imprimir</button>
      <a class="btn btn-sm btn-gris" href="<?= url('reportes.php') ?>">Otros reportes</a>
    </div>
  </div>

  <div class="tarjeta-cuerpo">
    <form class="filtros" method="get">
      <input type="hidden" name="r" value="<?= e($reporte) ?>">
      <?php if (in_array($reporte, ['entradas', 'salidas', 'por_usuario', 'compras_producto', 'ventas_producto'], true)): ?>
        <?php if (in_array($reporte, ['compras_producto', 'ventas_producto'], true)): ?>
          <div class="campo">
            <label>Mes</label>
            <input type="month" id="mesRapido" value="<?= e(substr($desde, 0, 7)) ?>"
                   onchange="var m=this.value; if(!m) return;
                             document.querySelector('[name=desde]').value = m + '-01';
                             document.querySelector('[name=hasta]').value =
                               new Date(m.slice(0,4), m.slice(5,7), 0).toISOString().slice(0,10);">
          </div>
        <?php endif; ?>
        <div class="campo"><label>Desde</label><input type="date" name="desde" value="<?= e($desde) ?>"></div>
        <div class="campo"><label>Hasta</label><input type="date" name="hasta" value="<?= e($hasta) ?>"></div>
        <?php if (in_array($reporte, ['compras_producto', 'ventas_producto'], true)): ?>
          <div class="campo">
            <label>Código de producto (opcional)</label>
            <input type="text" name="codigo" value="<?= e($codigo ?? '') ?>" placeholder="Ej: S127">
          </div>
          <p style="color:var(--suave);font-size:12px;width:100%;margin:2px 0 0">
            Para un solo día, pongan la misma fecha en "Desde" y "Hasta". Para un mes completo, usen el
            selector "Mes" (llena Desde/Hasta solo).
            <strong>Con el código de un producto puesto</strong>, en vez del resumen general sale el
            detalle día por día de ESE producto: qué días se movió y cuánto.
          </p>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($reporte !== 'por_almacen' && $reporte !== 'por_usuario'): ?>
        <div class="campo">
          <label>Almacén</label>
          <select name="almacen_id">
            <option value="">Todos</option>
            <?php foreach ($almacenes as $id => $nom): ?>
              <option value="<?= $id ?>" <?= (string) $almacen === (string) $id ? 'selected' : '' ?>><?= e($nom) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <button class="btn">Generar</button>
    </form>
  </div>

  <div class="tabla-scroll">
    <?php if (!$datos): ?>
      <p class="vacio">Sin resultados para los criterios indicados.</p>
    <?php else: ?>
    <table class="tabla" id="tablaReporte">
      <?php
      // Cabeceras a partir de las claves del primer registro, con etiquetas legibles.
      $etiquetas = [
        'codigo' => 'Código', 'descripcion' => 'Producto', 'unidad' => 'Und',
        'stock_actual' => 'Stock', 'stock_minimo' => 'Mínimo', 'costo_promedio' => 'C. promedio',
        'fisico' => 'Físico', 'reservado' => 'Reservado', 'disponible' => 'Disponible',
        'valor' => 'Valorizado', 'categoria' => 'Categoría', 'marca' => 'Marca',
        'serie_numero' => 'Serie', 'fecha' => 'Fecha', 'almacen' => 'Almacén',
        'proveedor' => 'Proveedor', 'motivo' => 'Motivo', 'destino' => 'Destino',
        'total' => 'Total', 'items' => 'Ítems', 'usuario' => 'Usuario', 'nombres' => 'Nombres',
        'rol' => 'Rol', 'entradas' => 'Entradas', 'salidas' => 'Salidas',
        'ajustes' => 'Ajustes', 'productos' => 'Productos', 'cantidad' => 'Cantidad',
        'documentos' => 'Documentos', 'documento' => 'Documento', 'cliente' => 'Cliente',
        'costo_unitario' => 'C. unitario',
      ];
      $ocultas = ['id', 'producto_id', 'almacen_id', 'proveedor_id', 'usuario_id', 'estado',
                  'creado_en', 'observacion', 'tipo_documento', 'nro_documento'];
      $cols = array_values(array_diff(array_keys($datos[0]), $ocultas));
      $numericas = ['stock_actual','stock_minimo','costo_promedio','fisico','reservado','disponible',
                    'valor','total','items','cantidad','entradas','salidas','ajustes','productos','documentos',
                    'costo_unitario'];
      ?>
      <thead><tr>
        <?php foreach ($cols as $c): ?>
          <th class="<?= in_array($c, $numericas, true) ? 'num' : '' ?>"><?= e($etiquetas[$c] ?? ucfirst(str_replace('_', ' ', $c))) ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($datos as $fila): ?>
        <tr>
          <?php foreach ($cols as $c): ?>
            <?php $v = $fila[$c] ?? ''; ?>
            <td class="<?= in_array($c, $numericas, true) ? 'num' : '' ?>">
              <?php if ($c === 'fecha'): ?><?= Vista::fecha($v) ?>
              <?php elseif (in_array($c, $numericas, true) && is_numeric($v)): ?><?= Vista::num($v) ?>
              <?php else: ?><?= e($v ?? '—') ?><?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php
      $sumables = array_values(array_intersect($cols, ['valor', 'total', 'cantidad']));
      if ($sumables): ?>
      <tfoot><tr>
        <?php foreach ($cols as $i => $c): ?>
          <?php if (in_array($c, $sumables, true)): ?>
            <th class="num"><?= Vista::num(array_sum(array_column($datos, $c))) ?></th>
          <?php else: ?>
            <th class="<?= $i === 0 ? '' : 'num' ?>"><?= $i === 0 ? 'TOTALES' : '' ?></th>
          <?php endif; ?>
        <?php endforeach; ?>
      </tr></tfoot>
      <?php endif; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
