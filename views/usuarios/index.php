<?php $puede = Auth::puede('usuarios.gestionar'); ?>

<?php if ($puede): ?>
<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2><?= $editar ? 'Editar usuario' : 'Nuevo usuario' ?></h2>
    <?php if ($editar): ?><a class="btn btn-sm btn-gris" href="<?= url('usuarios.php') ?>">Cancelar edición</a><?php endif; ?>
  </div>
  <form method="post" class="tarjeta-cuerpo" autocomplete="off">
    <?= Csrf::campo() ?>
    <input type="hidden" name="id" value="<?= e($editar['id'] ?? '') ?>">
    <div class="form-grid">
      <div class="campo">
        <label>Usuario *</label>
        <input type="text" name="usuario" required maxlength="60" value="<?= e($editar['usuario'] ?? '') ?>">
      </div>
      <div class="campo">
        <label>Nombres completos *</label>
        <input type="text" name="nombres" required maxlength="120" value="<?= e($editar['nombres'] ?? '') ?>">
      </div>
      <div class="campo">
        <label>Email</label>
        <input type="email" name="email" maxlength="150" value="<?= e($editar['email'] ?? '') ?>">
      </div>
      <div class="campo">
        <label>Rol en <?= e(Empresa::nombre()) ?> *</label>
        <select name="rol_id" required>
          <?php foreach ($roles as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (int) ($editar['rol_id'] ?? 0) === $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Contraseña <?= $editar ? '(dejar vacío para no cambiarla)' : '*' ?></label>
        <input type="password" name="clave" <?= $editar ? '' : 'required' ?> minlength="6">
      </div>
      <div class="campo">
        <label>Estado</label>
        <select name="estado">
          <option value="1" <?= (int) ($editar['estado'] ?? 1) === 1 ? 'selected' : '' ?>>Activo</option>
          <option value="0" <?= (int) ($editar['estado'] ?? 1) === 0 ? 'selected' : '' ?>>Inactivo</option>
        </select>
      </div>
    </div>
    <div class="acciones" style="margin-top:12px">
      <button class="btn" type="submit"><?= $editar ? 'Actualizar' : 'Crear usuario' ?></button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>
      <?= $todos ? 'Todos los usuarios del sistema' : 'Con acceso a ' . e(Empresa::nombre()) ?>
      (<?= count($usuarios) ?>)
    </h2>
    <div class="acciones">
      <form method="get" style="display:flex;gap:6px">
        <?php if ($todos): ?><input type="hidden" name="todos" value="1"><?php endif; ?>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar usuario...">
        <button class="btn btn-sm">Buscar</button>
      </form>
      <?php /* Ver a todos es otra pregunta: quiénes existen en el sistema, no
               quiénes pueden trabajar aquí. Por eso hay que pedirlo. */
      if ($puedeVerTodos): ?>
        <a class="btn btn-sm btn-gris"
           href="<?= url('usuarios.php' . ($todos ? '' : '?todos=1')) ?>">
          <?= $todos ? 'Ver sólo los de esta empresa' : 'Ver todos los del sistema' ?>
        </a>
      <?php endif; ?>
    </div>
  </div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr>
        <th>Usuario</th><th>Nombres</th><th>Email</th><th>Rol en <?= e(Empresa::nombre()) ?></th><th class="num">Empresas</th>
        <th>Último acceso</th><th>Estado</th><?php if ($puede): ?><th>Acciones</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($usuarios as $u): ?>
        <tr>
          <td><strong><?= e($u['usuario']) ?></strong></td>
          <td><?= e($u['nombres']) ?></td>
          <td><?= e($u['email'] ?? '—') ?></td>
          <td><?= $u['rol'] ? '<span class="badge">' . e($u['rol']) . '</span>' : '<span class="badge badge-warn">no accede aquí</span>' ?></td>
          <td class="num"><?= (int) $u['empresas'] ?></td>
          <td><?= $u['ultimo_acceso'] ? Vista::fecha($u['ultimo_acceso'], true) : 'Nunca' ?></td>
          <td><span class="badge <?= (int) $u['estado'] === 1 ? 'badge-ok' : 'badge-error' ?>"><?= (int) $u['estado'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
          <?php if ($puede): ?>
          <td>
            <div class="acciones">
              <a class="btn btn-sm btn-gris" href="<?= url('usuarios.php?id=' . $u['id']) ?>">Editar</a>
              <?php if ((int) $u['id'] !== Auth::id()): ?>
                <a class="btn btn-sm btn-rojo" href="<?= url('usuarios.php?a=eliminar&id=' . $u['id']) ?>"
                   data-confirmar="¿Retirar a <?= e($u['usuario']) ?> de <?= e(Empresa::nombre()) ?>?<?= (int) $u['empresas'] > 1 ? ' Conservará el acceso a sus otras empresas.' : ' Es su única empresa: la cuenta quedará eliminada o desactivada.' ?>">Quitar</a>
              <?php endif; ?>
            </div>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
