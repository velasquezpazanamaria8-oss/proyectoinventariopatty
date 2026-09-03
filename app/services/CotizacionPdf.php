<?php
/**
 * Arma el PDF de una cotización con el diseño de su empresa.
 *
 * Todo lo que distingue a una empresa de otra —logo y dónde va, color, título,
 * numeración, qué columnas y con qué nombre, condiciones, cuentas y firmas—
 * sale de cotizacion_config. El armado es uno solo: así las nueve empresas
 * emiten documentos distintos sin mantener nueve plantillas.
 */
class CotizacionPdf
{
    private Pdf   $pdf;
    private array $cot;
    private array $cfg;
    private array $emp;

    private const GRIS  = '#64748B';
    private const LINEA = '#CBD5E1';
    private const TEXTO = '#1F2A36';

    public function __construct(array $cotizacion, array $config, array $empresa)
    {
        $this->cot = $cotizacion;
        $this->cfg = $config;
        $this->emp = $empresa;

        $this->pdf = new Pdf($config['titulo'] . ' ' . CotizacionConfig::formatoNumero($config, (int) $cotizacion['numero']));
        $this->pdf->sinCabecera();
    }

    public function generar(): string
    {
        // En modo libre la cabecera y el pie los compuso el usuario en el
        // lienzo; la tabla es la misma en los dos, porque es lo único que no
        // se coloca a mano: crece con los ítems y parte de página sola.
        if (($this->cfg['modo'] ?? 'SIMPLE') === 'LIBRE') {
            $this->cabeceraLibre();
            $this->tabla();
            $this->pieLibre();
            return $this->pdf->generar();
        }

        $this->cabecera();
        $this->bloqueCliente();
        $this->tabla();
        $this->totales();
        $this->pie();
        return $this->pdf->generar();
    }

    public function nombreArchivo(): string
    {
        $num = CotizacionConfig::formatoNumero($this->cfg, (int) $this->cot['numero']);
        return 'Cotizacion-' . preg_replace('/[^A-Za-z0-9\-]/', '', $num) . '.pdf';
    }

    // ------------------------------------------------------------------

    private function cabecera(): void
    {
        $p = $this->pdf;
        $m = $p->margen();
        $util = $p->util();
        $y = $p->alto() - $m;

        // Franja de color: título, número, referencia y fechas van TODOS aquí
        // dentro. Antes la referencia se pintaba debajo y chocaba con el nombre
        // de la empresa cuando el logo estaba a la izquierda.
        $lineasBanda = ['N° ' . CotizacionConfig::formatoNumero($this->cfg, (int) $this->cot['numero'])];
        if (!empty($this->cfg['etiqueta_ref'])) {
            $lineasBanda[] = $this->cfg['etiqueta_ref']
                . (!empty($this->cot['referencia']) ? '  ' . $this->cot['referencia'] : '');
        }
        if ($this->cfg['mostrar_fecha']) {
            $lineasBanda[] = 'Fecha: ' . Vista::fecha($this->cot['fecha']);
        }
        if (!empty($this->cot['valida_hasta'])) {
            $lineasBanda[] = 'Válida hasta: ' . Vista::fecha($this->cot['valida_hasta']);
        }

        $altoBanda = 22 + count($lineasBanda) * 11 + 6;
        $p->caja($m, $y, $util, $altoBanda, $this->cfg['color']);
        $p->escribir($this->cfg['titulo'], $m, $y - 16, $util - 8, 'der', true, 13, '#FFFFFF');

        $yb = $y - 30;
        foreach ($lineasBanda as $i => $linea) {
            $p->escribir($linea, $m, $yb, $util - 8, 'der', $i === 0, $i === 0 ? 10 : 8, '#FFFFFF');
            $yb -= 11;
        }

        $y -= $altoBanda + 12;

        // Logo y datos del emisor, cada uno a un lado.
        $escalaLogo = max(0.4, min(2.5, ((int) ($this->cfg['logo_escala'] ?? 100)) / 100));
        $anchoLogo = 150 * $escalaLogo;
        $altoLogo  = 58 * $escalaLogo;
        $pos = $this->cfg['logo_posicion'];
        $hayLogo = !empty($this->cfg['logo_ruta']) && is_file(BASE_PATH . '/' . $this->cfg['logo_ruta']);

        $xLogo = match ($pos) {
            'CENTRO'  => $m + ($util - $anchoLogo) / 2,
            'DERECHA' => $m + $util - $anchoLogo,
            default   => $m,
        };
        if ($hayLogo) {
            $p->imagen(BASE_PATH . '/' . $this->cfg['logo_ruta'], $xLogo, $y, $anchoLogo, $altoLogo);
        }

        // Con el logo centrado el emisor va debajo, y entonces dispone de todo
        // el ancho; si van uno al lado del otro, se le deja lo que queda libre.
        // Con la mitad exacta, una razón social larga salía cortada.
        $anchoEmisor = $pos === 'CENTRO' ? $util : $util - $anchoLogo - 16;
        if ($pos === 'CENTRO') {
            $xEmisor = $this->cfg['emisor_derecha'] ? $m + $util - $anchoEmisor : $m;
            $yE = $y - ($hayLogo ? $altoLogo + 10 : 0);
        } else {
            $xEmisor = $pos === 'DERECHA' ? $m : $m + $util - $anchoEmisor;
            $yE = $y;
        }

        $etq = (bool) $this->cfg['emisor_etiquetas'];
        $p->escribir($this->emp['razon_social'], $xEmisor, $yE - 9, $anchoEmisor, 'izq', true, 10.5, self::TEXTO);
        $yE -= 21;
        foreach ($this->datosEmisor($etq) as $linea) {
            foreach ($p->repartir($linea, $anchoEmisor, 8) as $l) {
                $p->escribir($l, $xEmisor, $yE, $anchoEmisor, 'izq', false, 8, self::GRIS);
                $yE -= 10;
            }
        }

        $abajoLogo = $y - ($hayLogo && $pos !== 'CENTRO' ? $altoLogo : 0);
        $p->moverA(min($yE, $abajoLogo) - 10);
    }

    private function datosEmisor(bool $etq): array
    {
        $out = [];
        if (!empty($this->emp['direccion'])) {
            $out[] = ($etq ? 'Dirección: ' : '') . $this->emp['direccion'];
        }
        $out[] = ($etq ? 'RUC: ' : 'RUC ') . $this->emp['ruc'];
        if (!empty($this->emp['email'])) {
            $out[] = ($etq ? 'E-mail: ' : '') . $this->emp['email'];
        }
        if ($this->cfg['mostrar_telefono'] && !empty($this->emp['telefono'])) {
            $out[] = ($etq ? 'Teléfono: ' : '') . $this->emp['telefono'];
        }
        return $out;
    }

    private function bloqueCliente(): void
    {
        $p = $this->pdf;
        $m = $p->margen();
        $util = $p->util();
        $y = $p->cursor();

        $alto = 62;
        $p->caja($m, $y, $util, 15, '#F1F5F9');
        $p->marco($m, $y, $util, $alto, self::LINEA);
        $p->escribir('CLIENTE', $m, $y - 11, $util, 'izq', true, 8.5, $this->cfg['color']);

        // La empresa y la dirección ocupan la fila entera: son los que se cortan
        // si se les da media. El RUC y el correo sí caben a dos columnas.
        $yc = $y - 26;
        foreach (['Empresa' => $this->cot['cliente_nombre'],
                  'Dirección' => $this->cot['cliente_direccion'] ?: '-'] as $et => $valor) {
            $p->escribir($et . ':', $m + 6, $yc, 60, 'izq', false, 7.5, self::GRIS);
            $p->escribir((string) $valor, $m + 58, $yc, $util - 64, 'izq', false, 8.5, self::TEXTO);
            $yc -= 13;
        }
        $col = 0;
        foreach (['RUC' => $this->cot['cliente_ruc'] ?: '-',
                  'E-mail' => $this->cot['cliente_email'] ?: '-'] as $et => $valor) {
            $x = $m + 6 + $col * ($util / 2);
            $p->escribir($et . ':', $x, $yc, 60, 'izq', false, 7.5, self::GRIS);
            $p->escribir((string) $valor, $x + 52, $yc, $util / 2 - 60, 'izq', false, 8.5, self::TEXTO);
            $col++;
        }
        $p->moverA($y - $alto - 14);
    }

    // ------------------------------------------------------------------

    private function tabla(): void
    {
        $p = $this->pdf;
        $m = $p->margen();
        $util = $p->util();

        $cols = $this->cfg['columnas'];
        $suma = array_sum(array_column($cols, 'ancho')) ?: 1;
        foreach ($cols as &$c) {
            $c['pt']   = $util * $c['ancho'] / $suma;
            $c['alin'] = in_array($c['campo'], ['cantidad', 'precio', 'importe'], true) ? 'der'
                       : ($c['campo'] === 'unidad' ? 'centro' : 'izq');
        }
        unset($c);

        $this->encabezadoTabla($cols);

        foreach ($this->cot['detalle'] as $d) {
            $textoDesc = $d['descripcion'];
            $anchoDesc = 0;
            foreach ($cols as $c) {
                if ($c['campo'] === 'descripcion') $anchoDesc = $c['pt'];
            }
            $lineas = $p->repartir($textoDesc, $anchoDesc, 8);
            $altoFila = max(14, count($lineas) * 9.5 + 5);

            // Si no cabe, se pasa a otra página y se repite el encabezado.
            if ($p->cursor() - $altoFila < $m + 120) {
                $p->abrirPagina();
                $p->moverA($p->alto() - $m);
                $this->encabezadoTabla($cols);
            }

            $y = $p->cursor();
            $x = $m;
            foreach ($cols as $c) {
                $valor = $this->valorColumna($c['campo'], $d);
                if ($c['campo'] === 'descripcion') {
                    $yl = $y - 10;
                    foreach ($lineas as $l) {
                        $p->escribir($l, $x, $yl, $c['pt'], 'izq', false, 8, self::TEXTO);
                        $yl -= 9.5;
                    }
                } else {
                    $p->escribir($valor, $x, $y - 10, $c['pt'], $c['alin'], false, 8, self::TEXTO);
                }
                $x += $c['pt'];
            }
            $p->linea($m, $y - $altoFila, $util, '#E2E8F0', 0.4);
            $p->moverA($y - $altoFila);
        }
    }

    private function encabezadoTabla(array $cols): void
    {
        $p = $this->pdf;
        $m = $p->margen();
        $y = $p->cursor();

        $p->caja($m, $y, $p->util(), 16, $this->cfg['color']);
        $x = $m;
        foreach ($cols as $c) {
            $p->escribir($c['titulo'], $x, $y - 11, $c['pt'], $c['alin'], true, 7.5, '#FFFFFF');
            $x += $c['pt'];
        }
        $p->moverA($y - 16);
    }

    private function valorColumna(string $campo, array $d): string
    {
        return match ($campo) {
            'unidad'      => (string) ($d['unidad'] ?? ''),
            'cantidad'    => Vista::num($d['cantidad']),
            'descripcion' => (string) $d['descripcion'],
            'precio'      => Vista::num($d['precio_unitario'], 2),
            'importe'     => Vista::num($d['importe'], 2),
            default       => '',
        };
    }

    // ------------------------------------------------------------------

    private function totales(): void
    {
        $p = $this->pdf;
        $m = $p->margen();
        $util = $p->util();
        $y = $p->cursor() - 8;

        $anchoCaja = 190;
        $x = $m + $util - $anchoCaja;
        $simbolo = $this->emp['simbolo'] ?? 'S/';

        foreach ([['SUBTOTAL', $this->cot['subtotal'], false],
                  ['IGV (18%)', $this->cot['igv'], false],
                  ['TOTAL', $this->cot['total'], true]] as [$et, $val, $fuerte]) {
            if ($fuerte) {
                $p->caja($x, $y, $anchoCaja, 18, $this->cfg['color']);
                $p->escribir($et, $x, $y - 12, 90, 'izq', true, 9.5, '#FFFFFF');
                $p->escribir($simbolo . ' ' . Vista::num($val, 2), $x + 90, $y - 12,
                    $anchoCaja - 90, 'der', true, 9.5, '#FFFFFF');
                $y -= 18;
            } else {
                $p->escribir($et, $x, $y - 11, 90, 'izq', false, 8.5, self::GRIS);
                $p->escribir($simbolo . ' ' . Vista::num($val, 2), $x + 90, $y - 11,
                    $anchoCaja - 90, 'der', false, 8.5, self::TEXTO);
                $y -= 14;
            }
        }
        $p->moverA($y - 10);
    }

    private function pie(): void
    {
        $p = $this->pdf;
        $m = $p->margen();
        $util = $p->util();

        foreach ([['notas', null], ['condiciones', 'TÉRMINOS Y CONDICIONES']] as [$campo, $titulo]) {
            $texto = trim((string) ($this->cfg[$campo] ?? ''));
            if ($texto === '') {
                continue;
            }
            $lineas = preg_split('/\r\n|\r|\n/', $texto);
            $alto = count($lineas) * 9.5 + ($titulo ? 16 : 8);
            if ($p->cursor() - $alto < $m + 70) {
                $p->abrirPagina();
                $p->moverA($p->alto() - $m);
            }
            $y = $p->cursor();
            if ($titulo) {
                $p->escribir($titulo, $m, $y - 9, $util, 'izq', true, 8.5, $this->cfg['color']);
                $y -= 15;
            }
            foreach ($lineas as $l) {
                foreach ($p->repartir($l, $util, 7.5) as $trozo) {
                    $p->escribir($trozo, $m, $y - 7, $util, 'izq', false, 7.5, self::GRIS);
                    $y -= 9.5;
                }
            }
            $p->moverA($y - 6);
        }

        // Firmas: dos líneas al pie, con el nombre debajo.
        $y = max($p->cursor() - 26, $m + 46);
        $anchoFirma = $util * 0.38;
        foreach ([[$m, $this->cfg['firma_izq'] ?: $this->emp['razon_social']],
                  [$m + $util - $anchoFirma, $this->cfg['firma_der'] ?: 'CLIENTE']] as [$x, $nombre]) {
            $p->linea($x, $y, $anchoFirma, self::LINEA, 0.8);
            $p->escribir((string) $nombre, $x, $y - 11, $anchoFirma, 'centro', false, 8, self::GRIS);
        }
        $p->moverA($y - 24);
    }

    // ------------------------------------------------------------------
    // Modo libre: los bloques que el usuario colocó en el lienzo.
    //
    // Las coordenadas del lienzo se cuentan desde arriba, que es como se mira
    // una hoja; el PDF cuenta desde abajo. La resta se hace aquí, en un solo
    // sitio, para que ningún bloque tenga que saberlo.
    // ------------------------------------------------------------------

    private function cabeceraLibre(): void
    {
        $origen = $this->pdf->alto();                 // el y = 0 del lienzo
        foreach ($this->bloquesDe('cabecera') as $b) {
            $this->pintar($b, $origen);
        }
        // La tabla empieza donde termina la zona de cabecera, ponga el usuario
        // sus bloques donde los ponga: así una cabecera alta no se come los
        // ítems ni una baja deja media hoja en blanco.
        $this->pdf->moverA($origen - (float) ($this->cfg['alto_cabecera'] ?? 250));
    }

    private function pieLibre(): void
    {
        $p = $this->pdf;
        $origen = $p->cursor() - 6;

        // Cuánto ocupa el pie no se sabe hasta pintarlo, pero sí dónde empieza
        // su bloque más bajo. Si eso ya no cabe se pasa a otra página entera:
        // partir las firmas queda peor que una hoja de más.
        $masBajo = 0;
        foreach ($this->bloquesDe('pie') as $b) {
            $masBajo = max($masBajo, $b['y'] + 30);
        }
        if ($origen - $masBajo < $p->margen()) {
            $p->abrirPagina();
            $origen = $p->alto() - $p->margen();
        }

        foreach ($this->bloquesDe('pie') as $b) {
            $this->pintar($b, $origen);
        }
        $p->moverA($origen - $masBajo);
    }

    /** Los bloques de una zona, ya validados. */
    private function bloquesDe(string $zona): array
    {
        $bloques = CotizacionDiseno::normalizar($this->cfg['bloques'] ?? null);
        return array_values(array_filter($bloques, fn($b) => $b['zona'] === $zona));
    }

    private function pintar(array $b, float $origen): void
    {
        $p = $this->pdf;
        $y = $origen - $b['y'];               // borde superior del bloque, ya en PDF

        switch ($b['tipo']) {
            case 'caja':
                $this->bloqueCaja($b, $y);
                break;

            case 'linea':
                $p->linea($b['x'], $y, $b['w'], $b['color'], 0.8);
                break;

            case 'logo':
                $ruta = (string) ($this->cfg['logo_ruta'] ?? '');
                if ($ruta !== '' && is_file(BASE_PATH . '/' . $ruta)) {
                    $p->imagen(BASE_PATH . '/' . $ruta, $b['x'], $y, $b['w'], $b['h']);
                }
                break;

            case 'texto':
                $this->textoLibre($b['texto'], $b, $y);
                break;

            case 'dato':
                $txt = CotizacionDiseno::valor($b['clave'], $this->cot, $this->emp, $this->cfg);
                // Un dato que no existe —una empresa sin teléfono, una cotización
                // sin referencia— no deja el hueco pintado: no sale y ya.
                if ($txt !== '') {
                    $this->textoLibre($txt, $b, $y);
                }
                break;

            case 'cliente':
                $this->fichaCliente($b, $y);
                break;

            case 'totales':
                $this->bloqueTotales($b, $y);
                break;

            case 'firmas':
                $this->bloqueFirmas($b, $y);
                break;

            case 'firma1':
                $this->bloqueFirma1($b, $y);
                break;

            case 'parrafo':
                $this->bloqueParrafo($b, $y);
                break;
        }
    }

    /** Texto de un bloque: se parte en varias líneas si no cabe en el ancho. */
    /**
     * El recuadro de color: el relleno de siempre, más marco y texto adentro
     * opcionales, para que sirva de verdad como "cuadro con algo escrito"
     * y no sólo de franja decorativa.
     */
    private function bloqueCaja(array $b, float $yTop): void
    {
        $p = $this->pdf;
        $p->caja($b['x'], $yTop, $b['w'], $b['h'], $b['color']);
        if (!empty($b['marco'])) {
            $p->marco($b['x'], $yTop, $b['w'], $b['h'], self::LINEA);
        }
        $contenido = trim((string) ($b['contenido'] ?? ''));
        if ($contenido === '') {
            return;
        }
        $tam = $b['tam'] ?: 9;
        $pad = 6.0;
        $x = $b['x'] + $pad;
        $ancho = $b['w'] - $pad * 2;
        $lineas = [];
        foreach (preg_split('/\r\n|\r|\n/', $contenido) as $renglon) {
            foreach ($p->repartir($renglon, $ancho, $tam) as $l) {
                $lineas[] = $l;
            }
        }
        $y = $yTop - $pad - $tam * 0.85;
        foreach ($lineas as $linea) {
            if ($yTop - $y > $b['h'] - $pad) break;  // no se sale del recuadro
            $p->escribir($linea, $x, $y, $ancho, $b['alin'],
                (bool) ($b['negrita'] ?? false), $tam, $b['colorTexto'] ?? '#FFFFFF');
            $y -= $tam * 1.2;
        }
    }

    private function textoLibre(string $txt, array $b, float $yTop): void
    {
        $p = $this->pdf;

        // Con fondo se deja aire alrededor del texto y el cuadro se mide
        // según cuántas líneas ocupa de verdad, igual que en bloqueParrafo().
        $fondo = $b['fondo'] ?? null;
        $pad = $fondo ? 5.0 : 0.0;
        $x = $b['x'] + $pad;
        $ancho = $b['w'] - $pad * 2;

        // repartir() trata los saltos de línea como espacios y los borra: si
        // alguien escribió una lista renglón por renglón, hay que partir por
        // esos renglones PRIMERO y sólo dejar que repartir() envuelva cada
        // uno cuando no entra en el ancho, o la lista sale hecha un párrafo.
        $lineas = [];
        foreach (preg_split('/\r\n|\r|\n/', $txt) as $renglon) {
            foreach ($p->repartir($renglon, $ancho, $b['tam']) as $l) {
                $lineas[] = $l;
            }
        }
        if ($fondo) {
            $alto = count($lineas) * $b['tam'] * 1.2 + $pad * 2;
            $p->caja($b['x'], $yTop, $b['w'], $alto, $fondo);
        }

        $y = $yTop - $pad - $b['tam'] * 0.85; // del borde superior a la línea base
        foreach ($lineas as $linea) {
            $p->escribir($linea, $x, $y, $ancho, $b['alin'],
                (bool) $b['negrita'], $b['tam'], $b['color']);
            $y -= $b['tam'] * 1.2;
        }
    }

    private function fichaCliente(array $b, float $yTop): void
    {
        $p = $this->pdf;
        $x = $b['x'];
        $ancho = $b['w'];
        $color = $b['color'];
        $r = $b['textos'];
        $tam = $b['tam'] ?: 8.5;
        $negrita = (bool) ($b['negrita'] ?? false);
        $alto = 62;

        // La franja del rótulo lleva un fondo claro de fábrica; si se eligió
        // uno propio, se usa ése en vez del gris de siempre.
        $p->caja($x, $yTop, $ancho, 15, $b['fondo'] ?? '#F1F5F9');
        $p->marco($x, $yTop, $ancho, $alto, self::LINEA);
        if ($r['rotulo'] !== '') {
            $p->escribir($r['rotulo'], $x, $yTop - 11, $ancho, 'izq', true, $tam, $color);
        }

        // La empresa y la dirección ocupan la fila entera: son las que se cortan
        // si se les da media. RUC, teléfono y correo caben a tres columnas.
        $y = $yTop - 26;
        foreach ([[$r['empresa'], $this->cot['cliente_nombre']],
                  [$r['direccion'], $this->cot['cliente_direccion'] ?: '-']] as [$et, $valor]) {
            $p->escribir($et !== '' ? $et . ':' : '', $x + 6, $y, 60, 'izq', $negrita, $tam - 1, $color);
            $p->escribir((string) $valor, $x + 58, $y, $ancho - 64, 'izq', $negrita, $tam, $color);
            $y -= 13;
        }
        $col = 0;
        $anchoCol = $ancho / 3;
        foreach ([[$r['ruc'], $this->cot['cliente_ruc'] ?: '-'],
                  [$r['telefono'], $this->cot['cliente_telefono'] ?: '-'],
                  [$r['email'], $this->cot['cliente_email'] ?: '-']] as [$et, $valor]) {
            $xc = $x + 6 + $col * $anchoCol;
            $p->escribir($et !== '' ? $et . ':' : '', $xc, $y, 45, 'izq', $negrita, $tam - 1, $color);
            $p->escribir((string) $valor, $xc + 40, $y, $anchoCol - 46, 'izq', $negrita, $tam, $color);
            $col++;
        }
    }

    private function bloqueTotales(array $b, float $yTop): void
    {
        $p = $this->pdf;
        $x = $b['x'];
        $ancho = $b['w'];
        $color = $b['color'];
        $r = $b['textos'];
        $simbolo  = $this->emp['simbolo'] ?? 'S/';
        $etiqueta = min(90, $ancho * 0.5);
        $tam = $b['tam'] ?: 8.5;
        $negrita = (bool) ($b['negrita'] ?? false);
        $y = $yTop;

        foreach ([[$r['subtotal'], $this->cot['subtotal'], false],
                  [$r['igv'], $this->cot['igv'], false],
                  [$r['total'], $this->cot['total'], true]] as [$et, $val, $fuerte]) {
            $importe = $simbolo . ' ' . Vista::num($val, 2);
            if ($fuerte) {
                $p->caja($x, $y, $ancho, $tam * 2.1, $color);
                $p->escribir($et, $x, $y - $tam * 1.4, $etiqueta, 'izq', true, $tam + 1, '#FFFFFF');
                $p->escribir($importe, $x + $etiqueta, $y - $tam * 1.4, $ancho - $etiqueta, 'der', true, $tam + 1, '#FFFFFF');
                $y -= $tam * 2.1;
            } else {
                $p->escribir($et, $x, $y - $tam * 1.3, $etiqueta, 'izq', $negrita, $tam, $color);
                $p->escribir($importe, $x + $etiqueta, $y - $tam * 1.3, $ancho - $etiqueta, 'der', $negrita, $tam, $color);
                $y -= $tam * 1.65;
            }
        }
    }

    private function bloqueFirmas(array $b, float $yTop): void
    {
        $p = $this->pdf;
        $x = $b['x'];
        $ancho = $b['w'];
        $color = $b['color'];
        $anchoFirma = $ancho * 0.38;

        // Si en el lienzo no se escribió nada, mandan los nombres de las
        // opciones: son los mismos que usa el modo simple.
        $izq = $b['textos']['izq'] ?: ($this->cfg['firma_izq'] ?: $this->emp['razon_social']);
        $der = $b['textos']['der'] ?: ($this->cfg['firma_der'] ?: 'CLIENTE');

        if (!empty($b['fondo'])) {
            // No hay un "Alto" configurable en esta pieza: se deja el aire
            // justo para la línea y el nombre debajo.
            $p->caja($x, $yTop + 6, $ancho, 26, $b['fondo']);
        }

        foreach ([[$x, $izq], [$x + $ancho - $anchoFirma, $der]] as [$xf, $nombre]) {
            $p->linea($xf, $yTop, $anchoFirma, self::LINEA, 0.8);
            $p->escribir((string) $nombre, $xf, $yTop - 11, $anchoFirma, 'centro',
                (bool) ($b['negrita'] ?? false), $b['tam'] ?: 8, $color);
        }
    }

    /**
     * Una sola firma, con la imagen escaneada arriba de la línea si se subió
     * una; si no, queda la línea en blanco para firmar a mano, igual que las
     * demás.
     */
    private function bloqueFirma1(array $b, float $yTop): void
    {
        $p = $this->pdf;
        $x = $b['x'];
        $ancho = $b['w'];
        $color = $b['color'];
        $nombre = $b['textos']['nombre'] ?? '';

        if (!empty($b['fondo'])) {
            $p->caja($x, $yTop, $ancho, $b['h'], $b['fondo']);
        }

        $altoImg = max(0, $b['h'] - 24);   // deja sitio para la línea y el nombre
        if (!empty($b['imagen'])) {
            $abs = BASE_PATH . '/' . $b['imagen'];
            $info = is_file($abs) ? @getimagesize($abs) : false;
            if ($info) {
                // Se calcula el ancho real que va a ocupar (respetando su
                // proporción) para poder centrarla; el "Ancho" y "Alto" del
                // bloque son el máximo que puede llegar a ocupar, no un
                // tamaño fijo, así que agrandar el bloque agranda la imagen.
                [$px, $py] = $info;
                $escala = min($ancho / $px, $altoImg / $py);
                $wReal = $px * $escala;
                $p->imagen($abs, $x + ($ancho - $wReal) / 2, $yTop, $ancho, $altoImg);
            }
        }

        $yLinea = $yTop - $altoImg - 6;
        $p->linea($x, $yLinea, $ancho, self::LINEA, 0.8);
        if ($nombre !== '') {
            $p->escribir($nombre, $x, $yLinea - 11, $ancho, 'centro',
                (bool) ($b['negrita'] ?? false), $b['tam'] ?: 8, $color);
        }
    }

    private function bloqueParrafo(array $b, float $yTop): void
    {
        $clave = $b['clave'];
        $ancho = $b['w'];
        $tam = $b['tam'];
        $color = $b['color'];
        $titulo = $b['textos']['titulo'];
        $texto = trim((string) ($this->cfg[$clave] ?? ''));
        if ($texto === '') {
            return;                            // sin condiciones no hay título suelto
        }
        $p = $this->pdf;

        // Con fondo se deja aire alrededor del texto; sin fondo, igual que
        // siempre, pegado al borde del bloque.
        $fondo = $b['fondo'] ?? null;
        $pad = $fondo ? 8.0 : 0.0;
        $x = $b['x'] + $pad;
        $anchoTexto = $ancho - $pad * 2;

        // Se arma primero la lista de líneas (con el reparto de ancho ya
        // resuelto) para saber cuánto va a medir el bloque ANTES de dibujar
        // el fondo: si no, el cuadro saldría de un tamaño fijo que no
        // acompaña a un texto más largo o más corto.
        $lineas = [];
        if ($titulo !== '') {
            $lineas[] = [$titulo, true, $tam + 1];
        }
        foreach (preg_split('/\r\n|\r|\n/', $texto) as $linea) {
            foreach ($p->repartir($linea, $anchoTexto, $tam) as $trozo) {
                $lineas[] = [$trozo, false, $tam];
            }
        }

        $altoTotal = $pad * 2;
        foreach ($lineas as [, $esTitulo]) {
            $altoTotal += $esTitulo ? $tam * 2 : $tam * 1.3;
        }
        if ($fondo) {
            $p->caja($b['x'], $yTop, $ancho, $altoTotal, $fondo);
        }

        $negrita = (bool) ($b['negrita'] ?? false);
        $y = $yTop - $pad;
        foreach ($lineas as [$txt, $esTitulo, $tamLinea]) {
            $p->escribir($txt, $x, $y - $tamLinea, $anchoTexto, 'izq', $esTitulo || $negrita, $tamLinea, $color);
            $y -= $esTitulo ? $tam * 2 : $tam * 1.3;
        }
    }
}
