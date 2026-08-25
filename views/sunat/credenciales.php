<?php
$hay = $cred !== null;
$estado = $cred['estado'] ?? 'SIN_PROBAR';
?>

<div class="tarjeta">
  <div class="tarjeta-cab">
    <h2>Credenciales SUNAT de <?= e(Empresa::nombre()) ?></h2>
    <?php if ($hay): ?>
      <span class="badge <?= $estado === 'OK' ? 'badge-ok' : ($estado === 'ERROR' ? 'badge-error' : 'badge-warn') ?>">
        <?= $estado === 'OK' ? 'Conexión verificada' : ($estado === 'ERROR' ? 'Con error' : 'Sin probar') ?>
      </span>
    <?php endif; ?>
  </div>

  <div class="tarjeta-cuerpo">
    <div class="alerta alerta-info">
      Con estas credenciales el sistema consulta a SUNAT <strong>en nombre de la empresa</strong>:
      qué comprobantes hay en cada período (SIRE) y el detalle de cada uno (XML, PDF).
      La Clave SOL y el secreto de API se guardan <strong>cifrados</strong> (AES-256-GCM) y sólo
      se descifran en memoria al momento de la consulta; nunca se muestran ni quedan en el historial.
    </div>

    <?php if ($hay && $cred['mensaje']): ?>
      <div class="alerta <?= $estado === 'OK' ? 'alerta-ok' : 'alerta-error' ?>">
        <strong>Última prueba<?= $cred['verificado_en'] ? ' (' . Vista::fecha($cred['verificado_en'], true) . ')' : '' ?>:</strong>
        <?= e($cred['mensaje']) ?>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <?= Csrf::campo() ?>
      <input type="hidden" name="op" value="guardar">

      <div class="form-grid">
        <div class="campo">
          <label>RUC *</label>
          <input type="text" name="ruc" required maxlength="11" pattern="[0-9]{11}"
                 value="<?= e($cred['ruc'] ?? '') ?>" placeholder="11 dígitos">
        </div>
        <div class="campo">
          <label>Usuario SOL *</label>
          <input type="text" name="usuario_sol" required maxlength="60"
                 value="<?= e($cred['usuario_sol'] ?? '') ?>">
        </div>
        <div class="campo">
          <label>Clave SOL <?= $hay ? '(dejar vacío para no cambiarla)' : '*' ?></label>
          <input type="password" name="clave_sol" <?= $hay ? '' : 'required' ?>
                 placeholder="<?= $hay ? '•••••••• guardada' : '' ?>">
        </div>
        <div class="campo">
          <label>ID de API (client_id)</label>
          <input type="text" name="client_id" maxlength="120" value="<?= e($cred['client_id'] ?? '') ?>">
        </div>
        <div class="campo">
          <label>Clave de API (client_secret) <?= !empty($cred['client_secret']) ? '(vacío = no cambiar)' : '' ?></label>
          <input type="password" name="client_secret"
                 placeholder="<?= !empty($cred['client_secret']) ? '•••••••• guardada' : '' ?>">
        </div>
      </div>

      <p style="color:var(--suave);font-size:12.5px;margin-bottom:0">
        El ID y la clave de API salen del portal SOL, menú <em>Credenciales de API</em>.
        La aplicación debe tener alcance <strong>Desktop</strong> — con "Web" el acceso no funciona.
      </p>

      <div class="acciones" style="margin-top:14px">
        <button class="btn" type="submit">Guardar credenciales</button>
      </div>
    </form>
  </div>

  <?php if ($hay): ?>
  <div class="tarjeta-cuerpo" style="border-top:1px solid var(--linea)">
    <div class="acciones">
      <form method="post" style="display:inline">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="probar">
        <button class="btn btn-verde" type="submit">Probar conexión</button>
      </form>

      <form method="post" style="display:inline">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="permisos">
        <button class="btn btn-naranja" type="submit"
                data-confirmar="Esto inicia sesión en el portal SOL y habilita los recursos de la API en la cuenta de la empresa. ¿Continuar?">
          Habilitar permisos de API
        </button>
      </form>

      <form method="post" style="display:inline">
        <?= Csrf::campo() ?>
        <input type="hidden" name="op" value="eliminar">
        <button class="btn btn-rojo" type="submit"
                data-confirmar="¿Eliminar las credenciales SUNAT de esta empresa?">Eliminar</button>
      </form>
    </div>
    <p style="color:var(--suave);font-size:12.5px;margin:10px 0 0">
      <strong>Probar conexión</strong> usa la API oficial y no modifica nada.
      <strong>Habilitar permisos</strong> sí escribe en la configuración de SUNAT: inicia sesión en el
      portal y marca los recursos que faltan. Hágalo una sola vez por empresa; SUNAT bloquea usuarios
      tras varios intentos seguidos de inicio de sesión.
    </p>
  </div>
  <?php endif; ?>
</div>

<?php if ($resultado && $resultado['tipo'] === 'prueba'): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Resultado de la prueba</h2></div>
  <div class="tarjeta-cuerpo">
    <div class="kpis">
      <div class="kpi <?= $resultado['ok'] ? 'exito' : 'peligro' ?>">
        <div class="etiqueta">Conexión</div>
        <div class="valor" style="font-size:20px"><?= $resultado['ok'] ? 'Correcta' : 'Con error' ?></div>
      </div>
      <div class="kpi <?= $resultado['sire'] ? 'exito' : 'peligro' ?>">
        <div class="etiqueta">SIRE (migeigv)</div>
        <div class="valor" style="font-size:20px"><?= $resultado['sire'] ? 'Habilitado' : 'Falta' ?></div>
      </div>
      <div class="kpi <?= $resultado['cpe'] ? 'exito' : 'alerta' ?>">
        <div class="etiqueta">Descarga CPE</div>
        <div class="valor" style="font-size:20px"><?= $resultado['cpe'] ? 'Habilitada' : 'Falta' ?></div>
      </div>
    </div>

    <?php if ($resultado['periodos']): ?>
      <h3 style="font-size:14px;margin-top:6px">Períodos disponibles en el SIRE</h3>
      <div class="tabla-scroll">
        <table class="tabla">
          <thead><tr><th>Período</th><th>Estado de la declaración</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($resultado['periodos'], 0, 12, true) as $per => $est): ?>
            <tr>
              <td><?= e(substr($per, 4, 2) . '/' . substr($per, 0, 4)) ?></td>
              <td><span class="badge <?= stripos($est, 'no') === 0 ? 'badge-warn' : 'badge-ok' ?>"><?= e($est) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($resultado['recursos']): ?>
      <h3 style="font-size:14px;margin-top:16px">Recursos incluidos en el token</h3>
      <ul style="columns:2;color:var(--suave);font-size:12.5px">
        <?php foreach ($resultado['recursos'] as $r): ?><li><?= e($r) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($resultado && $resultado['tipo'] === 'permisos'): ?>
<div class="tarjeta">
  <div class="tarjeta-cab"><h2>Permisos de API</h2></div>
  <div class="tarjeta-cuerpo">
    <p>Aplicación <strong><?= e($resultado['app']) ?></strong> · client_id <code><?= e($resultado['client_id']) ?></code></p>

    <?php if ($resultado['agregados']): ?>
      <div class="alerta alerta-ok">
        Se habilitaron: <?= e(implode(', ', $resultado['agregados'])) ?>
      </div>
    <?php else: ?>
      <div class="alerta alerta-info">Ya estaban habilitados todos los recursos necesarios.</div>
    <?php endif; ?>

    <?php if ($resultado['perdidas']): ?>
      <div class="alerta alerta-error">
        Desaparecieron estas rutas: <?= e(implode(', ', $resultado['perdidas'])) ?>.
        Revíselo en el portal SOL.
      </div>
    <?php endif; ?>

    <h3 style="font-size:14px">Rutas habilitadas ahora (<?= count($resultado['rutas']) ?>)</h3>
    <ul style="columns:2;color:var(--suave);font-size:12.5px">
      <?php foreach ($resultado['rutas'] as $r): ?><li><?= e($r) ?></li><?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>
