<?php
$etiquetasCampo = [
    'nombre' => 'Nombre', 'descripcion' => 'Descripción', 'estado' => 'Estado',
    'codigo' => 'Código', 'decimales' => 'Decimales', 'ruc' => 'RUC',
    'razon_social' => 'Razón social', 'telefono' => 'Teléfono',
    'email' => 'Email', 'direccion' => 'Dirección',
];
$puedeGestionar = Auth::puede('catalogos.gestionar');
?>

<?php if ($puedeGestionar): ?>
<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2><?= $editar ? 'Editar' : 'Nuevo' ?> registro — <?= e($meta['etiqueta']) ?></h2>
    <?php if ($editar): ?>
      <a class="btn btn-sm btn-gris" href="<?= url('catalogos.php?t=' . $tabla) ?>">Cancelar edición</a>
    <?php endif; ?>
  </div>
  <form method="post" class="tarjeta-cuerpo">
    <?= Csrf::campo() ?>
    <input type="hidden" name="id" value="<?= e($editar['id'] ?? '') ?>">
    <div class="form-grid">
      <?php foreach ($meta['campos'] as $campo): ?>
        <div class="campo">
          <label><?= e($etiquetasCampo[$campo] ?? ucfirst($campo)) ?></label>
          <?php if ($campo === 'estado'): ?>
            <select name="estado">
              <option value="1" <?= (int) ($editar['estado'] ?? 1) === 1 ? 'selected' : '' ?>>Activo</option>
              <option value="0" <?= (int) ($editar['estado'] ?? 1) === 0 ? 'selected' : '' ?>>Inactivo</option>
            </select>
          <?php elseif ($campo === 'decimales'): ?>
            <input type="number" min="0" max="4" name="decimales" value="<?= e($editar['decimales'] ?? 2) ?>">
          <?php elseif ($campo === 'email'): ?>
            <input type="email" name="email" value="<?= e($editar['email'] ?? '') ?>">
          <?php else: ?>
            <input type="text" name="<?= e($campo) ?>" value="<?= e($editar[$campo] ?? '') ?>"
                   <?= in_array($campo, ['nombre', 'razon_social', 'codigo'], true) ? 'required' : '' ?>>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="acciones" style="margin-top:12px">
      <button class="btn" type="submit"><?= $editar ? 'Actualizar' : 'Guardar' ?></button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2><?= e($meta['etiqueta']) ?> (<?= count($filas) ?>)</h2>
    <div class="acciones">
      <form method="get" style="display:flex;gap:6px">
        <input type="hidden" name="t" value="<?= e($tabla) ?>">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar...">
        <button class="btn btn-sm">Buscar</button>
      </form>
      <?php /* Los clientes de verdad ya están en las ventas descargadas de
               SUNAT, con su RUC y su razón social tal como los declaró el
               emisor. Traerlos de ahí ahorra teclearlos y evita erratas. */
      if ($tabla === 'clientes' && $porSembrar > 0 && $puedeGestionar): ?>
        <form method="post" style="margin:0">
          <?= Csrf::campo() ?>
          <input type="hidden" name="op" value="sembrar_clientes">
          <button class="btn btn-sm btn-verde"
                  data-confirmar="Se agregarán <?= (int) $porSembrar ?> cliente(s) tomados de sus ventas ya importadas. Los que ya existan no se tocan. ¿Continuar?">
            Traer <?= (int) $porSembrar ?> de mis ventas
          </button>
        </form>
      <?php endif; ?>
      <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaCat','<?= e($tabla) ?>')">CSV</button>
    </div>
  </div>
  <div class="tabla-scroll">
    <?php if (!$filas): ?>
      <p class="vacio">No hay registros.</p>
    <?php else: ?>
    <table class="tabla" id="tablaCat">
      <thead><tr>
        <?php foreach ($meta['campos'] as $campo): ?>
          <th><?= e($etiquetasCampo[$campo] ?? ucfirst($campo)) ?></th>
        <?php endforeach; ?>
        <?php if ($puedeGestionar): ?><th class="no-export">Acciones</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($filas as $f): ?>
        <tr>
          <?php foreach ($meta['campos'] as $campo): ?>
            <td>
              <?php if ($campo === 'estado'): ?>
                <span class="badge <?= (int) $f['estado'] === 1 ? 'badge-ok' : 'badge-error' ?>"><?= (int) $f['estado'] === 1 ? 'Activo' : 'Inactivo' ?></span>
              <?php else: ?><?= e($f[$campo] ?? '—') ?><?php endif; ?>
            </td>
          <?php endforeach; ?>
          <?php if ($puedeGestionar): ?>
          <td class="no-export">
            <div class="acciones">
              <a class="btn btn-sm btn-gris" href="<?= url('catalogos.php?t=' . $tabla . '&id=' . $f['id']) ?>">Editar</a>
              <a class="btn btn-sm btn-rojo" href="<?= url('catalogos.php?t=' . $tabla . '&a=eliminar&id=' . $f['id']) ?>"
                 data-confirmar="¿Eliminar este registro?">Eliminar</a>
            </div>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
