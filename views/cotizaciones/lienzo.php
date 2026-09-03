<?php
/**
 * El lienzo. La hoja se dibuja a escala 1 (1 punto de PDF = 1 píxel), así que
 * lo que se ve colocado aquí cae en el mismo sitio en el documento.
 */
$libre = ($cfg['modo'] ?? 'SIMPLE') === 'LIBRE';

$estado = [
    'bloques'      => $cfg['bloques'],
    'altoCabecera' => (int) $cfg['alto_cabecera'],
    'valores'      => $valores,
    'color'        => $cfg['color'],
    'condiciones'  => (string) ($cfg['condiciones'] ?? ''),
    'notas'        => (string) ($cfg['notas'] ?? ''),
    'firmaIzq'     => (string) ($cfg['firma_izq'] ?? '') ?: $empresa['razon_social'],
    'firmaDer'     => (string) ($cfg['firma_der'] ?? '') ?: 'CLIENTE',
    'logo'         => !empty($cfg['logo_ruta'])
                        ? url('cotizacion_diseno.php?a=logo&v=' . urlencode((string) ($cfg['actualizado_en'] ?? '')))
                        : null,
    // Los datos del cliente sin etiqueta: la ficha pone la suya delante, y con
    // los valores ya etiquetados salía «Dirección: Dirección: ...».
    'cliente'      => [
        'nombre'    => $muestra['cliente_nombre'],
        'direccion' => $muestra['cliente_direccion'],
        'ruc'       => $muestra['cliente_ruc'],
        'email'     => $muestra['cliente_email'],
    ],
    'rotulos'      => CotizacionDiseno::ROTULOS,
    'previa'       => url('cotizacion_previa.php'),
    // Subir y ver la foto de una firma van al mismo controlador del lienzo,
    // por eso no hace falta una acción propia: sólo estas dos URL.
    'subirFirma'   => url('cotizacion_lienzo.php'),
    'verFirma'     => url('cotizacion_lienzo.php?a=firma&r='),
];
?>

<div class="lienzo-barra">
  <div>
    <strong>Lienzo — <?= e($empresa['razon_social']) ?></strong>
    <span class="lienzo-ayuda">
      Arrastre los bloques. Ctrl+Z deshace y Ctrl+Y rehace.
      La tabla no se mueve: crece con las líneas de cada cotización.
    </span>
  </div>
  <div class="acciones">
    <button type="button" class="btn btn-sm btn-gris" id="btnDeshacer"
            title="Deshacer (Ctrl+Z)" disabled>↶ Deshacer</button>
    <button type="button" class="btn btn-sm btn-gris" id="btnRehacer"
            title="Rehacer (Ctrl+Y o Ctrl+Mayús+Z)" disabled>↷ Rehacer</button>
    <a class="btn btn-sm btn-gris" href="<?= url('cotizacion_diseno.php') ?>">Volver a las opciones</a>
    <form method="post" style="display:inline"
          data-confirmar="¿Volver a la disposición de fábrica? Se pierde lo que haya colocado.">
      <?= Csrf::campo() ?>
      <input type="hidden" name="a" value="restaurar">
      <button class="btn btn-sm btn-gris" type="submit">Restaurar</button>
    </form>
    <form method="post" style="display:inline" id="formGuardar">
      <?= Csrf::campo() ?>
      <input type="hidden" name="bloques" id="campoBloques">
      <input type="hidden" name="alto_cabecera" id="campoAlto">
      <input type="hidden" name="condiciones" id="campoCondiciones">
      <input type="hidden" name="notas" id="campoNotas">
      <label class="lienzo-usar">
        <input type="checkbox" name="libre" value="1" id="campoLibre" <?= $libre ? 'checked' : '' ?>>
        <span>Usar este diseño</span>
      </label>
      <button class="btn btn-sm" type="submit">Guardar</button>
    </form>
  </div>
</div>

<?php if (!$libre): ?>
  <div class="alerta alerta-info">
    Las cotizaciones de esta empresa se siguen emitiendo con el <strong>modo simple</strong>.
    Componga aquí con calma y, cuando le convenza, marque «usar este diseño» y guarde.
  </div>
<?php endif; ?>

<div class="lienzo-pantalla">

  <aside class="lienzo-panel">
    <div class="tarjeta">
      <div class="tarjeta-cab"><h2>Agregar bloque</h2></div>
      <div class="tarjeta-cuerpo lienzo-paleta">
        <p class="lienzo-nota">Toque uno para ponerlo en la hoja. Incluye firmas, recuadros y líneas.</p>
        <?php foreach ($piezas as $tipo => $pieza): ?>
          <button type="button" class="ficha ficha-pieza" data-nuevo="<?= e($tipo) ?>">
            <?= e($pieza['nombre']) ?>
            <small><?= implode(' / ', $pieza['zonas']) ?></small>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="tarjeta">
      <div class="tarjeta-cab"><h2>Datos</h2></div>
      <div class="tarjeta-cuerpo lienzo-paleta">
        <p class="lienzo-nota">Toque uno para ponerlo en la hoja.</p>
        <?php foreach ($datos as $grupo => $claves): ?>
          <h4><?= e($grupo) ?></h4>
          <?php foreach ($claves as $clave => $etiqueta): ?>
            <button type="button" class="ficha" data-nuevo="dato" data-clave="<?= e($clave) ?>">
              <?= e($etiqueta) ?>
            </button>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="tarjeta" id="panelProps">
      <div class="tarjeta-cab"><h2>Bloque</h2></div>
      <div class="tarjeta-cuerpo" id="props">
        <p class="lienzo-nota">Toque un bloque de la hoja para cambiarlo.</p>
      </div>
    </div>
  </aside>

  <div class="lienzo-hoja-col">
    <div class="hoja" id="hoja">
      <div class="zona zona-cabecera" id="zonaCabecera">
        <span class="zona-nombre">CABECERA</span>
      </div>
      <div class="divisor" id="divisor" title="Arrastre para cambiar dónde empieza la tabla"></div>
      <div class="banda-tabla">
        <span>TABLA DE ÍTEMS — crece con las líneas de cada cotización</span>
      </div>
      <div class="zona zona-pie" id="zonaPie">
        <span class="zona-nombre">PIE — se coloca a partir de donde termine la tabla</span>
      </div>
    </div>
  </div>

  <aside class="lienzo-previa">
    <div class="tarjeta previa-caja">
      <div class="tarjeta-cab">
        <h2>Vista previa</h2>
        <span class="previa-estado" id="previaEstado">al día</span>
      </div>
      <iframe name="marcoPrevia" id="marcoPrevia" title="Vista previa de la cotización"></iframe>
      <p class="previa-nota">
        El PDF de verdad, con este lienzo y una cotización de ejemplo.
      </p>
    </div>
  </aside>
</div>

<script>window.LIENZO = <?= json_encode($estado, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= url('assets/js/lienzo.js') ?>?v=<?= @filemtime(BASE_PATH . '/assets/js/lienzo.js') ?>"></script>
