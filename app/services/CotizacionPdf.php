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
        $anchoLogo = 150;
        $altoLogo  = 58;
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
                $p->caja($b['x'], $y, $b['w'], $b['h'], $b['color']);
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
                $this->fichaCliente($b['x'], $y, $b['w'], $b['color']);
                break;

            case 'totales':
                $this->bloqueTotales($b['x'], $y, $b['w'], $b['color']);
                break;

            case 'firmas':
                $this->bloqueFirmas($b['x'], $y, $b['w'], $b['color']);
                break;

            case 'parrafo':
                $this->bloqueParrafo($b['clave'], $b['x'], $y, $b['w'], $b['tam'], $b['color']);
                break;
        }
    }

    /** Texto de un bloque: se parte en varias líneas si no cabe en el ancho. */
    private function textoLibre(string $txt, array $b, float $yTop): void
    {
        $p = $this->pdf;
        $y = $yTop - $b['tam'] * 0.85;        // del borde superior a la línea base
        foreach ($p->repartir($txt, $b['w'], $b['tam']) as $linea) {
            $p->escribir($linea, $b['x'], $y, $b['w'], $b['alin'],
                (bool) $b['negrita'], $b['tam'], $b['color']);
            $y -= $b['tam'] * 1.2;
        }
    }

    private function fichaCliente(float $x, float $yTop, float $ancho, string $color): void
    {
        $p = $this->pdf;

        $p->caja($x, $yTop, $ancho, 15, '#F1F5F9');
        $p->marco($x, $yTop, $ancho, 62, self::LINEA);
        $p->escribir('CLIENTE', $x, $yTop - 11, $ancho, 'izq', true, 8.5, $color);

        // La empresa y la dirección ocupan la fila entera: son las que se cortan
        // si se les da media. El RUC y el correo sí caben a dos columnas.
        $y = $yTop - 26;
        foreach (['Empresa' => $this->cot['cliente_nombre'],
                  'Dirección' => $this->cot['cliente_direccion'] ?: '-'] as $et => $valor) {
            $p->escribir($et . ':', $x + 6, $y, 60, 'izq', false, 7.5, self::GRIS);
            $p->escribir((string) $valor, $x + 58, $y, $ancho - 64, 'izq', false, 8.5, self::TEXTO);
            $y -= 13;
        }
        $col = 0;
        foreach (['RUC' => $this->cot['cliente_ruc'] ?: '-',
                  'E-mail' => $this->cot['cliente_email'] ?: '-'] as $et => $valor) {
            $xc = $x + 6 + $col * ($ancho / 2);
            $p->escribir($et . ':', $xc, $y, 60, 'izq', false, 7.5, self::GRIS);
            $p->escribir((string) $valor, $xc + 52, $y, $ancho / 2 - 60, 'izq', false, 8.5, self::TEXTO);
            $col++;
        }
    }

    private function bloqueTotales(float $x, float $yTop, float $ancho, string $color): void
    {
        $p = $this->pdf;
        $simbolo  = $this->emp['simbolo'] ?? 'S/';
        $etiqueta = min(90, $ancho * 0.5);
        $y = $yTop;

        foreach ([['SUBTOTAL', $this->cot['subtotal'], false],
                  ['IGV (18%)', $this->cot['igv'], false],
                  ['TOTAL', $this->cot['total'], true]] as [$et, $val, $fuerte]) {
            $importe = $simbolo . ' ' . Vista::num($val, 2);
            if ($fuerte) {
                $p->caja($x, $y, $ancho, 18, $color);
                $p->escribir($et, $x, $y - 12, $etiqueta, 'izq', true, 9.5, '#FFFFFF');
                $p->escribir($importe, $x + $etiqueta, $y - 12, $ancho - $etiqueta, 'der', true, 9.5, '#FFFFFF');
                $y -= 18;
            } else {
                $p->escribir($et, $x, $y - 11, $etiqueta, 'izq', false, 8.5, self::GRIS);
                $p->escribir($importe, $x + $etiqueta, $y - 11, $ancho - $etiqueta, 'der', false, 8.5, self::TEXTO);
                $y -= 14;
            }
        }
    }

    private function bloqueFirmas(float $x, float $yTop, float $ancho, string $color): void
    {
        $p = $this->pdf;
        $anchoFirma = $ancho * 0.38;
        foreach ([[$x, $this->cfg['firma_izq'] ?: $this->emp['razon_social']],
                  [$x + $ancho - $anchoFirma, $this->cfg['firma_der'] ?: 'CLIENTE']] as [$xf, $nombre]) {
            $p->linea($xf, $yTop, $anchoFirma, self::LINEA, 0.8);
            $p->escribir((string) $nombre, $xf, $yTop - 11, $anchoFirma, 'centro', false, 8, $color);
        }
    }

    private function bloqueParrafo(string $clave, float $x, float $yTop, float $ancho,
                                   float $tam, string $color): void
    {
        $texto = trim((string) ($this->cfg[$clave] ?? ''));
        if ($texto === '') {
            return;                            // sin condiciones no hay título suelto
        }
        $p = $this->pdf;
        $y = $yTop;

        if ($clave === 'condiciones') {
            $p->escribir('TÉRMINOS Y CONDICIONES', $x, $y - $tam, $ancho, 'izq', true,
                $tam + 1, $this->cfg['color']);
            $y -= $tam * 2;
        }
        foreach (preg_split('/\r\n|\r|\n/', $texto) as $linea) {
            foreach ($p->repartir($linea, $ancho, $tam) as $trozo) {
                $p->escribir($trozo, $x, $y - $tam, $ancho, 'izq', false, $tam, $color);
                $y -= $tam * 1.3;
            }
        }
    }
}
