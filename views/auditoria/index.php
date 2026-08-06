<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Auditoría e historial de usuarios</h2>
    <button class="btn btn-sm btn-gris" onclick="exportarCSV('tablaAuditoria','auditoria')">Exportar CSV</button>
  </div>

  <div class="tarjeta-cuerpo">
    <form class="filtros" method="get">
      <div class="campo">
        <label>Usuario</label>
        <select name="usuario_id">
          <option value="">Todos</option>
          <?php foreach ($usuarios as $id => $nom): ?>
            <option value="<?= $id ?>" <?= (string) $filtros['usuario_id'] === (string) $id ? 'selected' : '' ?>><?= e($nom) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>Acción</label>
        <select name="accion">
          <option value="">Todas</option>
          <?php foreach (['LOGIN','LOGOUT','CREAR','EDITAR','ELIMINAR','DESACTIVAR','AJUSTE'] as $a): ?>
            <option <?= $filtros['accion'] === $a ? 'selected' : '' ?>><?= e($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo"><label>Desde</label><input type="date" name="desde" value="<?= e($filtros['desde']) ?>"></div>
      <div class="campo"><label>Hasta</label><input type="date" name="hasta" value="<?= e($filtros['hasta']) ?>"></div>
      <button class="btn">Filtrar</button>
      <a class="btn btn-gris" href="<?= url('auditoria.php') ?>">Limpiar</a>
    </form>
  </div>

  <div class="tabla-scroll">
    <?php if (!$registros): ?>
      <p class="vacio">No hay registros de auditoría con esos criterios.</p>
    <?php else: ?>
    <table class="tabla" id="tablaAuditoria">
      <thead><tr>
        <th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th>
        <th>ID</th><th>Detalle</th><th>IP</th>
      </tr></thead>
      <tbody>
      <?php foreach ($registros as $r): ?>
        <tr>
          <td><?= Vista::fecha($r['creado_en'], true) ?></td>
          <td><?= e($r['usuario'] ?? 'sistema') ?></td>
          <td><span class="badge"><?= e($r['accion']) ?></span></td>
          <td><?= e($r['entidad']) ?></td>
          <td><?= e($r['entidad_id'] ?? '—') ?></td>
          <td style="white-space:normal;max-width:420px;font-size:12px;color:var(--suave)">
            <?= e(mb_substr((string) $r['detalle'], 0, 220)) ?>
          </td>
          <td><?= e($r['ip'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
