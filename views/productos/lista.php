<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Listado de productos (<?= (int) $datos['total'] ?>)</h2>
    <div class="acciones">
      <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaProductos','productos')">Exportar CSV</button>
      <?php if (Auth::puede('productos.gestionar')): ?>
        <a class="btn btn-sm btn-verde" href="<?= url('productos_importar.php') ?>">Importar Excel</a>
        <a class="btn btn-sm" href="<?= url('productos.php?a=form') ?>">+ Nuevo producto</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="tarjeta-cuerpo">
    <form class="filtros" method="get">
      <div class="campo">
        <label>Buscar (código, nombre, categoría, marca)</label>
        <input type="text" name="q" value="<?= e($filtros['q']) ?>" placeholder="Escriba para buscar...">
      </div>
      <div class="campo">
        <label>Categoría</label>
        <select name="categoria_id">
          <option value="">Todas</option>
          <?php foreach ($categorias as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (string) $filtros['categoria_id'] === (string) $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Marca</label>
        <select name="marca_id">
          <option value="">Todas</option>
          <?php foreach ($marcas as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (string) $filtros['marca_id'] === (string) $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Estado</label>
        <select name="estado">
          <option value="">Todos</option>
          <option value="1" <?= $filtros['estado'] === '1' ? 'selected' : '' ?>>Activos</option>
          <option value="0" <?= $filtros['estado'] === '0' ? 'selected' : '' ?>>Inactivos</option>
        </select>
      </div>
      <button class="btn" type="submit">Filtrar</button>
      <a class="btn btn-gris" href="<?= url('productos.php') ?>">Limpiar</a>
    </form>
  </div>

  <div class="tabla-scroll">
    <?php if (!$datos['filas']): ?>
      <p class="vacio">No se encontraron productos con esos criterios.</p>
    <?php else: ?>
    <table class="tabla" id="tablaProductos">
      <thead><tr>
        <th>Código</th><th>Descripción</th><th>Categoría</th><th>Marca</th><th>Und</th>
        <th class="num">Stock</th><th class="num">Mínimo</th>
        <th class="num">P. Compra</th><th class="num">P. Venta</th>
        <th>Estado</th><th class="no-export">Acciones</th>
      </tr></thead>
      <tbody>
      <?php foreach ($datos['filas'] as $p): ?>
        <?php $bajo = (float) $p['stock_actual'] <= (float) $p['stock_minimo']; ?>
        <tr>
          <td><?= e($p['codigo']) ?></td>
          <td><?= e($p['descripcion']) ?></td>
          <td><?= e($p['categoria'] ?? '—') ?></td>
          <td><?= e($p['marca'] ?? '—') ?></td>
          <td><?= e($p['unidad']) ?></td>
          <td class="num"><?= $bajo ? '<span class="badge badge-warn">' . Vista::num($p['stock_actual']) . '</span>' : Vista::num($p['stock_actual']) ?></td>
          <td class="num"><?= Vista::num($p['stock_minimo']) ?></td>
          <td class="num"><?= Vista::num($p['precio_compra']) ?></td>
          <td class="num"><?= Vista::num($p['precio_venta']) ?></td>
          <td><span class="badge <?= (int) $p['estado'] === 1 ? 'badge-ok' : 'badge-error' ?>"><?= (int) $p['estado'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
          <td class="no-export">
            <div class="acciones">
              <?php if (Auth::puede('kardex.ver')): ?>
                <a class="btn btn-sm btn-gris" href="<?= url('kardex.php?producto_id=' . $p['id']) ?>">Kardex</a>
              <?php endif; ?>
              <?php if (Auth::puede('productos.gestionar')): ?>
                <a class="btn btn-sm btn-gris" href="<?= url('productos.php?a=form&id=' . $p['id']) ?>">Editar</a>
                <a class="btn btn-sm btn-rojo" href="<?= url('productos.php?a=eliminar&id=' . $p['id']) ?>"
                   data-confirmar="¿Eliminar el producto <?= e($p['codigo']) ?>?">Eliminar</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($datos['paginas'] > 1): ?>
  <div class="paginacion">
    <?php $qs = $_GET; for ($i = 1; $i <= $datos['paginas']; $i++): $qs['p'] = $i; ?>
      <?php if ($i === $datos['pagina']): ?>
        <span class="actual"><?= $i ?></span>
      <?php else: ?>
        <a href="<?= url('productos.php?' . http_build_query($qs)) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
