<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2><?= $producto ? 'Editar producto' : 'Nuevo producto' ?></h2>
    <a class="btn btn-sm btn-gris" href="<?= url('productos.php') ?>">Volver</a>
  </div>
  <form method="post" class="tarjeta-cuerpo">
    <?= Csrf::campo() ?>
    <input type="hidden" name="id" value="<?= e($producto['id'] ?? '') ?>">

    <div class="form-grid">
      <div class="campo">
        <label>Código *</label>
        <input type="text" name="codigo" required maxlength="40"
               value="<?= e($producto['codigo'] ?? ($_POST['codigo'] ?? '')) ?>">
      </div>
      <div class="campo" style="grid-column:span 2">
        <label>Descripción *</label>
        <input type="text" name="descripcion" required maxlength="255"
               value="<?= e($producto['descripcion'] ?? ($_POST['descripcion'] ?? '')) ?>">
      </div>
      <div class="campo">
        <label>Categoría</label>
        <select name="categoria_id">
          <option value="">— Sin categoría —</option>
          <?php foreach ($categorias as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (int) ($producto['categoria_id'] ?? 0) === $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Marca</label>
        <select name="marca_id">
          <option value="">— Sin marca —</option>
          <?php foreach ($marcas as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (int) ($producto['marca_id'] ?? 0) === $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Unidad de medida *</label>
        <select name="unidad_id" required>
          <?php foreach ($unidades as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (int) ($producto['unidad_id'] ?? 0) === $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Precio de compra</label>
        <input type="number" step="0.0001" min="0" name="precio_compra"
               value="<?= e($producto['precio_compra'] ?? '0') ?>">
      </div>
      <div class="campo">
        <label>Precio de venta</label>
        <input type="number" step="0.0001" min="0" name="precio_venta"
               value="<?= e($producto['precio_venta'] ?? '0') ?>">
      </div>
      <div class="campo">
        <label>Stock mínimo</label>
        <input type="number" step="0.0001" min="0" name="stock_minimo"
               value="<?= e($producto['stock_minimo'] ?? '0') ?>">
      </div>
      <div class="campo">
        <label>Estado</label>
        <select name="estado">
          <option value="1" <?= (int) ($producto['estado'] ?? 1) === 1 ? 'selected' : '' ?>>Activo</option>
          <option value="0" <?= (int) ($producto['estado'] ?? 1) === 0 ? 'selected' : '' ?>>Inactivo</option>
        </select>
      </div>
      <?php if ($producto): ?>
      <div class="campo">
        <label>Stock actual (solo lectura)</label>
        <input type="text" readonly value="<?= Vista::num($producto['stock_actual']) ?> <?= e($producto['unidad']) ?>">
      </div>
      <div class="campo">
        <label>Costo promedio (solo lectura)</label>
        <input type="text" readonly value="<?= Vista::num($producto['costo_promedio'], 4) ?>">
      </div>
      <?php endif; ?>
    </div>

    <p style="color:var(--suave);font-size:12.5px;margin-top:14px">
      El stock no se edita directamente: se modifica mediante entradas, salidas o ajustes de inventario.
    </p>

    <div class="acciones" style="margin-top:12px">
      <button class="btn" type="submit">Guardar</button>
      <a class="btn btn-gris" href="<?= url('productos.php') ?>">Cancelar</a>
    </div>
  </form>
</div>
