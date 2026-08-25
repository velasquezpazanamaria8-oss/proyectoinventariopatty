<?php
/**
 * Generador de PDF sin dependencias. RF-12.
 *
 * Escribe el archivo PDF 1.4 a mano usando las fuentes base (Helvetica), que
 * todo visor incluye, así que no hay que incrustar tipografías. Orientado a
 * reportes tabulares: cabecera con datos de la empresa, tabla paginada con
 * anchos y alineación por columna, y pie con numeración.
 *
 * Uso:
 *   $pdf = new Pdf('Kardex por producto', 'horizontal');
 *   $pdf->columnas([['Código', 60, 'izq'], ['Cantidad', 40, 'der']]);
 *   $pdf->fila(['P001', '12.50']);
 *   $pdf->descargar('kardex.pdf');
 */
class Pdf
{
    private array $paginas = [];
    private string $actual = '';
    private float $y = 0;

    private float $ancho;
    private float $alto;
    private float $margen = 30;

    private string $titulo;
    private array  $subtitulos = [];
    private array  $columnas = [];
    private int    $numFila = 0;

    /** Imágenes incrustadas: [nombre => ['datos'=>bin, 'ancho'=>px, 'alto'=>px, 'color'=>str]] */
    private array $imagenes = [];

    /**
     * Los reportes llevan una banda con los datos de la empresa en cada página.
     * Un documento con su propia cabecera —una cotización, por ejemplo— la
     * desactiva y se dibuja entera.
     */
    private bool $conCabecera = true;

    private const FUENTE   = 'F1';   // Helvetica
    private const NEGRITA  = 'F2';   // Helvetica-Bold
    private const ALTO_FILA = 15;

    public function __construct(string $titulo, string $orientacion = 'vertical')
    {
        $this->titulo = $titulo;
        // A4 en puntos: 595.28 x 841.89
        if ($orientacion === 'horizontal') {
            $this->ancho = 841.89;
            $this->alto  = 595.28;
        } else {
            $this->ancho = 595.28;
            $this->alto  = 841.89;
        }
    }

    public function subtitulo(string $texto): void
    {
        $this->subtitulos[] = $texto;
    }

    /**
     * @param array $cols cada una: [titulo, ancho_pt, alineacion izq|der|centro]
     */
    public function columnas(array $cols): void
    {
        $this->columnas = array_map(fn($c) => [
            'titulo' => $c[0],
            'ancho'  => (float) ($c[1] ?? 80),
            'alin'   => $c[2] ?? 'izq',
        ], $cols);
    }

    public function fila(array $valores, bool $negrita = false): void
    {
        if ($this->actual === '') {
            $this->nuevaPagina();
        }
        // Salto de página cuando ya no cabe otra fila.
        if ($this->y - self::ALTO_FILA < $this->margen + 24) {
            $this->cerrarPagina();
            $this->nuevaPagina();
        }

        $this->numFila++;
        $x = $this->margen;
        $y = $this->y;

        // Fondo alterno para facilitar la lectura
        if (!$negrita && $this->numFila % 2 === 0) {
            $this->actual .= sprintf("0.96 0.975 0.985 rg %.2f %.2f %.2f %.2f re f\n",
                $this->margen, $y - 4, $this->anchoUtil(), self::ALTO_FILA);
        }

        $fuente = $negrita ? self::NEGRITA : self::FUENTE;
        foreach (array_values($valores) as $i => $v) {
            $col = $this->columnas[$i] ?? ['ancho' => 80, 'alin' => 'izq'];
            $this->texto((string) $v, $x, $y, $col['ancho'], $col['alin'], $fuente, 8.5);
            $x += $col['ancho'];
        }

        // Línea separadora
        $this->actual .= sprintf("0.88 0.91 0.94 RG 0.4 w %.2f %.2f m %.2f %.2f l S\n",
            $this->margen, $y - 4.5, $this->margen + $this->anchoUtil(), $y - 4.5);

        $this->y -= self::ALTO_FILA;
    }

    /** Fila de totales, en negrita y con línea superior marcada. */
    public function filaTotal(array $valores): void
    {
        $this->actual .= sprintf("0.07 0.22 0.36 RG 0.9 w %.2f %.2f m %.2f %.2f l S\n",
            $this->margen, $this->y + 10, $this->margen + $this->anchoUtil(), $this->y + 10);
        $this->fila($valores, true);
    }

    // ------------------------------------------------------------------

    private function anchoUtil(): float
    {
        return $this->ancho - 2 * $this->margen;
    }

    private function nuevaPagina(): void
    {
        $this->actual = '';
        $this->y      = $this->alto - $this->margen;
        if ($this->conCabecera) {
            $this->cabecera();
        }
    }

    // ------------------------------------------------------------------
    // Composición libre — para documentos que no son un reporte tabular
    // ------------------------------------------------------------------

    /** Desactiva la banda automática de cabecera. */
    public function sinCabecera(): void
    {
        $this->conCabecera = false;
        if ($this->actual === '' && !$this->paginas) {
            $this->y = $this->alto - $this->margen;
        }
    }

    public function margen(): float  { return $this->margen; }
    public function ancho(): float   { return $this->ancho; }
    public function alto(): float    { return $this->alto; }
    public function util(): float    { return $this->anchoUtil(); }
    public function cursor(): float  { return $this->y; }
    public function moverA(float $y): void { $this->y = $y; }

    public function abrirPagina(): void
    {
        $this->cerrarPagina();
        $this->nuevaPagina();
    }

    /** Escribe un texto en una posición concreta. El color va en #RRGGBB. */
    public function escribir(string $txt, float $x, float $y, float $ancho, string $alin = 'izq',
                             bool $negrita = false, float $tam = 9, string $color = '#1F2A36'): void
    {
        $this->asegurarPagina();
        $this->texto($txt, $x, $y, $ancho, $alin, $negrita ? self::NEGRITA : self::FUENTE,
            $tam, self::rgb($color));
    }

    /** Rectángulo relleno. */
    public function caja(float $x, float $y, float $ancho, float $alto, string $color): void
    {
        $this->asegurarPagina();
        $this->actual .= sprintf("%s rg %.2f %.2f %.2f %.2f re f\n",
            self::rgb($color), $x, $y - $alto, $ancho, $alto);
    }

    /** Línea horizontal. */
    public function linea(float $x, float $y, float $ancho, string $color = '#CBD5E1', float $grosor = 0.6): void
    {
        $this->asegurarPagina();
        $this->actual .= sprintf("%s RG %.2f w %.2f %.2f m %.2f %.2f l S\n",
            self::rgb($color), $grosor, $x, $y, $x + $ancho, $y);
    }

    /** Recuadro sin relleno. */
    public function marco(float $x, float $y, float $ancho, float $alto,
                          string $color = '#CBD5E1', float $grosor = 0.6): void
    {
        $this->asegurarPagina();
        $this->actual .= sprintf("%s RG %.2f w %.2f %.2f %.2f %.2f re S\n",
            self::rgb($color), $grosor, $x, $y - $alto, $ancho, $alto);
    }

    /**
     * Coloca una imagen JPEG.
     *
     * Sólo JPEG: sus bytes se incrustan tal cual con el filtro DCTDecode, que es
     * el mismo que usa el formato, así que no hay que descomprimir ni recomprimir
     * nada. Un PNG obligaría a separar el canal alfa y rearmarlo; por eso los
     * logos se convierten a JPEG al subirlos.
     *
     * @return float el alto que ocupó, para saber por dónde seguir
     */
    public function imagen(string $ruta, float $x, float $y, float $anchoMax, float $altoMax): float
    {
        $this->asegurarPagina();

        $info = @getimagesize($ruta);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            return 0;                     // sin logo el documento sigue saliendo
        }
        [$px, $py] = $info;
        $canales = $info['channels'] ?? 3;
        if ($canales === 4) {
            return 0;                     // CMYK: poco frecuente y no vale la pena
        }

        // Se respeta la proporción dentro del hueco disponible.
        $escala = min($anchoMax / $px, $altoMax / $py);
        $w = $px * $escala;
        $h = $py * $escala;

        $clave = 'Im' . count($this->imagenes);
        $existente = array_search($ruta, array_column($this->imagenes, 'ruta', 'clave'), true);
        if ($existente !== false) {
            $clave = $existente;          // la misma imagen no se incrusta dos veces
        } else {
            $this->imagenes[$clave] = [
                'ruta'  => $ruta,
                'datos' => file_get_contents($ruta),
                'ancho' => $px,
                'alto'  => $py,
                'color' => $canales === 1 ? 'DeviceGray' : 'DeviceRGB',
            ];
        }

        // cm coloca y escala; q/Q aíslan la transformación del resto del flujo.
        $this->actual .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n",
            $w, $h, $x, $y - $h, $clave);

        return $h;
    }

    /** Ancho que ocuparía un texto, para poder repartirlo en líneas. */
    public function medir(string $txt, float $tam, bool $negrita = false): float
    {
        return $this->anchoTexto(self::aLatin($txt), $tam,
            $negrita ? self::NEGRITA : self::FUENTE);
    }

    /**
     * Parte un texto en líneas que quepan en el ancho dado.
     *
     * Una descripción de producto puede ser larguísima —«BARRENOS DE 4 SANDVICK
     * SANDVIK T38 4 PULG X 1 PULG ROCK»— y recortarla dejaría la cotización
     * diciendo algo distinto de lo que se ofrece.
     */
    public function repartir(string $txt, float $ancho, float $tam): array
    {
        $palabras = preg_split('/\s+/', trim($txt)) ?: [];
        $lineas = [];
        $linea = '';
        foreach ($palabras as $p) {
            $prueba = $linea === '' ? $p : $linea . ' ' . $p;
            if ($this->medir($prueba, $tam) <= $ancho - 8 || $linea === '') {
                $linea = $prueba;
            } else {
                $lineas[] = $linea;
                $linea = $p;
            }
        }
        if ($linea !== '') {
            $lineas[] = $linea;
        }
        return $lineas ?: [''];
    }

    /** Al componer libremente puede no haberse abierto ninguna página todavía. */
    private function asegurarPagina(): void
    {
        if ($this->actual === '' && !$this->paginas) {
            $this->nuevaPagina();
        }
    }

    /** #RRGGBB -> "r g b" en la escala 0..1 que usa PDF. */
    private static function rgb(string $hex): string
    {
        if (!preg_match('/^#?([0-9A-Fa-f]{6})$/', $hex, $m)) {
            return '0 0 0';
        }
        [$r, $g, $b] = sscanf($m[1], '%2x%2x%2x');
        return sprintf('%.3f %.3f %.3f', $r / 255, $g / 255, $b / 255);
    }

    private function cerrarPagina(): void
    {
        if ($this->actual !== '') {
            $this->paginas[] = $this->actual;
            $this->actual    = '';
        }
    }

    private function cabecera(): void
    {
        $emp = Empresa::hayActiva() ? Empresa::actual() : null;

        // Banda superior
        $this->actual .= sprintf("0.07 0.22 0.36 rg %.2f %.2f %.2f %.2f re f\n",
            $this->margen, $this->y - 34, $this->anchoUtil(), 38);

        if ($emp) {
            $this->texto($emp['razon_social'], $this->margen + 10, $this->y - 8, $this->anchoUtil() - 20, 'izq', self::NEGRITA, 11, '1 1 1');
            $this->texto('RUC ' . $emp['ruc'], $this->margen + 10, $this->y - 22, $this->anchoUtil() - 20, 'izq', self::FUENTE, 8, '0.78 0.87 0.94');
        }
        $this->texto($this->titulo, $this->margen + 10, $this->y - 8, $this->anchoUtil() - 20, 'der', self::NEGRITA, 11, '1 1 1');
        $this->texto('Emitido: ' . date('d/m/Y H:i'), $this->margen + 10, $this->y - 22, $this->anchoUtil() - 20, 'der', self::FUENTE, 8, '0.78 0.87 0.94');

        $this->y -= 48;

        foreach ($this->subtitulos as $s) {
            $this->texto($s, $this->margen, $this->y, $this->anchoUtil(), 'izq', self::FUENTE, 8.5, '0.39 0.45 0.55');
            $this->y -= 12;
        }
        if ($this->subtitulos) {
            $this->y -= 4;
        }

        // Cabecera de la tabla
        if ($this->columnas) {
            $this->actual .= sprintf("0.11 0.37 0.57 rg %.2f %.2f %.2f %.2f re f\n",
                $this->margen, $this->y - 4, $this->anchoUtil(), 16);
            $x = $this->margen;
            foreach ($this->columnas as $c) {
                $this->texto($c['titulo'], $x, $this->y, $c['ancho'], $c['alin'], self::NEGRITA, 8.5, '1 1 1');
                $x += $c['ancho'];
            }
            $this->y -= 20;
        }
    }

    /** Dibuja texto recortado al ancho de la celda. */
    private function texto(string $txt, float $x, float $y, float $ancho, string $alin,
                           string $fuente, float $tam, string $color = '0 0 0'): void
    {
        $txt = $this->recortar($txt, $ancho - 8, $tam, $fuente);
        $w   = $this->anchoTexto($txt, $tam, $fuente);

        $px = match ($alin) {
            'der'    => $x + $ancho - 4 - $w,
            'centro' => $x + ($ancho - $w) / 2,
            default  => $x + 4,
        };

        $this->actual .= sprintf("BT %s rg /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $color, $fuente, $tam, $px, $y, self::escapar($txt));
    }

    /** Ancho aproximado en puntos (Helvetica ≈ 0.5 em por carácter). */
    /**
     * Ancho real de un texto, en puntos.
     *
     * Las fuentes base no son de paso fijo: en Helvetica una «W» mide 944
     * milésimas de em y una «i» 222. Calcularlo con una media aplanada hacía que
     * el texto en mayúsculas —los títulos, las cabeceras de columna— se saliera
     * por la derecha al alinearlo a ese lado, porque se estimaba más estrecho de
     * lo que luego se dibujaba.
     */
    private function anchoTexto(string $txt, float $tam, string $fuente = self::FUENTE): float
    {
        $tabla = $fuente === self::NEGRITA ? self::anchosNegrita() : self::anchosNormal();
        $suma = 0;
        $len = strlen($txt);
        for ($i = 0; $i < $len; $i++) {
            $suma += $tabla[ord($txt[$i])] ?? 556;
        }
        return $suma / 1000 * $tam;
    }

    /** Recorta un texto para que quepa, midiendo de verdad carácter a carácter. */
    private function recortar(string $txt, float $ancho, float $tam, string $fuente = self::FUENTE): string
    {
        $txt = self::aLatin($txt);
        if ($this->anchoTexto($txt, $tam, $fuente) <= $ancho) {
            return $txt;
        }
        $corte = '';
        foreach (str_split($txt) as $ch) {
            if ($this->anchoTexto($corte . $ch . '.', $tam, $fuente) > $ancho) {
                break;
            }
            $corte .= $ch;
        }
        return ($corte === '' ? substr($txt, 0, 1) : $corte) . '.';
    }

    /**
     * Anchura de cada carácter en milésimas de em, según las métricas oficiales
     * de Helvetica. Sólo se listan los tramos que cambian; el resto toma 556,
     * que es el ancho de un dígito y una aproximación segura.
     */
    private static function anchosNormal(): array
    {
        static $t = null;
        if ($t !== null) {
            return $t;
        }
        $t = array_fill(0, 256, 556);
        $pares = [
            32=>278, 33=>278, 34=>355, 35=>556, 36=>556, 37=>889, 38=>667, 39=>191,
            40=>333, 41=>333, 42=>389, 43=>584, 44=>278, 45=>333, 46=>278, 47=>278,
            58=>278, 59=>278, 60=>584, 61=>584, 62=>584, 63=>556, 64=>1015,
            65=>667, 66=>667, 67=>722, 68=>722, 69=>667, 70=>611, 71=>778, 72=>722,
            73=>278, 74=>500, 75=>667, 76=>556, 77=>833, 78=>722, 79=>778, 80=>667,
            81=>778, 82=>722, 83=>667, 84=>611, 85=>722, 86=>667, 87=>944, 88=>667,
            89=>667, 90=>611, 91=>278, 92=>278, 93=>278, 94=>469, 95=>556, 96=>333,
            97=>556, 98=>556, 99=>500, 100=>556, 101=>556, 102=>278, 103=>556, 104=>556,
            105=>222, 106=>222, 107=>500, 108=>222, 109=>833, 110=>556, 111=>556, 112=>556,
            113=>556, 114=>333, 115=>500, 116=>278, 117=>556, 118=>500, 119=>722, 120=>500,
            121=>500, 122=>500, 123=>334, 124=>260, 125=>334, 126=>584,
            // Latin-1: acentuadas con el ancho de su letra base
            161=>333, 176=>400, 186=>365, 170=>370, 191=>611,
            192=>667, 193=>667, 194=>667, 195=>667, 196=>667, 197=>667, 198=>1000, 199=>722,
            200=>667, 201=>667, 202=>667, 203=>667, 204=>278, 205=>278, 206=>278, 207=>278,
            209=>722, 210=>778, 211=>778, 212=>778, 213=>778, 214=>778, 217=>722, 218=>722,
            219=>722, 220=>722, 221=>667,
            224=>556, 225=>556, 226=>556, 227=>556, 228=>556, 229=>556, 230=>889, 231=>500,
            232=>556, 233=>556, 234=>556, 235=>556, 236=>222, 237=>222, 238=>222, 239=>222,
            241=>556, 242=>556, 243=>556, 244=>556, 245=>556, 246=>556, 249=>556, 250=>556,
            251=>556, 252=>556, 253=>500, 255=>500,
        ];
        foreach ($pares as $c => $w) { $t[$c] = $w; }
        return $t;
    }

    /** Lo mismo para Helvetica-Bold, que es sensiblemente más ancha. */
    private static function anchosNegrita(): array
    {
        static $t = null;
        if ($t !== null) {
            return $t;
        }
        $t = array_fill(0, 256, 556);
        $pares = [
            32=>278, 33=>333, 34=>474, 35=>556, 36=>556, 37=>889, 38=>722, 39=>238,
            40=>333, 41=>333, 42=>389, 43=>584, 44=>278, 45=>333, 46=>278, 47=>278,
            58=>333, 59=>333, 60=>584, 61=>584, 62=>584, 63=>611, 64=>975,
            65=>722, 66=>722, 67=>722, 68=>722, 69=>667, 70=>611, 71=>778, 72=>722,
            73=>278, 74=>556, 75=>722, 76=>611, 77=>833, 78=>722, 79=>778, 80=>667,
            81=>778, 82=>722, 83=>667, 84=>611, 85=>722, 86=>667, 87=>944, 88=>667,
            89=>667, 90=>611, 91=>333, 92=>278, 93=>333, 94=>584, 95=>556, 96=>333,
            97=>556, 98=>611, 99=>556, 100=>611, 101=>556, 102=>333, 103=>611, 104=>611,
            105=>278, 106=>278, 107=>556, 108=>278, 109=>889, 110=>611, 111=>611, 112=>611,
            113=>611, 114=>389, 115=>556, 116=>333, 117=>611, 118=>556, 119=>778, 120=>556,
            121=>556, 122=>500, 123=>389, 124=>280, 125=>389, 126=>584,
            161=>333, 176=>400, 186=>365, 170=>370, 191=>611,
            192=>722, 193=>722, 194=>722, 195=>722, 196=>722, 197=>722, 198=>1000, 199=>722,
            200=>667, 201=>667, 202=>667, 203=>667, 204=>278, 205=>278, 206=>278, 207=>278,
            209=>722, 210=>778, 211=>778, 212=>778, 213=>778, 214=>778, 217=>722, 218=>722,
            219=>722, 220=>722, 221=>667,
            224=>556, 225=>556, 226=>556, 227=>556, 228=>556, 229=>556, 230=>889, 231=>556,
            232=>556, 233=>556, 234=>556, 235=>556, 236=>278, 237=>278, 238=>278, 239=>278,
            241=>611, 242=>611, 243=>611, 244=>611, 245=>611, 246=>611, 249=>611, 250=>611,
            251=>611, 252=>611, 253=>556, 255=>556,
        ];
        foreach ($pares as $c => $w) { $t[$c] = $w; }
        return $t;
    }

    /** Las fuentes base usan WinAnsi: se convierte desde UTF-8. */
    private static function aLatin(string $s): string
    {
        $conv = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        return $conv === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $conv;
    }

    private static function escapar(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $s);
    }

    // --- Ensamblado del archivo ---------------------------------------

    public function generar(): string
    {
        $this->cerrarPagina();
        if (!$this->paginas) {
            $this->nuevaPagina();
            $this->cerrarPagina();
        }

        $total = count($this->paginas);
        $objs  = [];

        // 1 catálogo, 2 páginas, 3/4 fuentes; luego por página: página + contenido
        $idsPaginas = [];
        for ($i = 0; $i < $total; $i++) {
            $idsPaginas[] = 5 + $i * 2;
        }

        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Count $total /Kids ["
                 . implode(' ', array_map(fn($id) => "$id 0 R", $idsPaginas)) . "] >>";
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        // Las imágenes van después de las páginas, cada una en su propio objeto.
        // El recurso se declara en TODAS las páginas: cuesta nada y evita tener
        // que llevar la cuenta de en cuál se usó cada una.
        $idImagen = 5 + $total * 2;
        $recursoImagenes = '';
        foreach ($this->imagenes as $clave => $img) {
            $objs[$idImagen] = "<< /Type /XObject /Subtype /Image"
                . " /Width {$img['ancho']} /Height {$img['alto']}"
                . " /ColorSpace /{$img['color']} /BitsPerComponent 8"
                . " /Filter /DCTDecode /Length " . strlen($img['datos']) . " >>\n"
                . "stream\n" . $img['datos'] . "\nendstream";
            $recursoImagenes .= "/$clave $idImagen 0 R ";
            $idImagen++;
        }
        $recursoImagenes = $recursoImagenes === ''
            ? '' : "/XObject << $recursoImagenes>> ";

        foreach ($this->paginas as $i => $contenido) {
            $idPag = 5 + $i * 2;
            $idCnt = $idPag + 1;

            // Pie con numeración, añadido al cerrar
            $pie = sprintf("BT 0.45 0.5 0.58 rg /%s 7.5 Tf %.2f %.2f Td (%s) Tj ET\n",
                self::FUENTE, $this->margen, $this->margen - 8,
                self::escapar(self::aLatin('Página ' . ($i + 1) . ' de ' . $total
                    . '  ·  ' . $this->titulo)));
            $flujo = $contenido . $pie;

            $objs[$idPag] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] "
                . "/Resources << /Font << /%s 3 0 R /%s 4 0 R >> %s>> /Contents %d 0 R >>",
                $this->ancho, $this->alto, self::FUENTE, self::NEGRITA, $recursoImagenes, $idCnt);

            $objs[$idCnt] = "<< /Length " . strlen($flujo) . " >>\nstream\n" . $flujo . "endstream";
        }

        ksort($objs);

        $pdf         = "%PDF-1.4\n";
        $desviaciones = [];
        foreach ($objs as $id => $cuerpo) {
            $desviaciones[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$cuerpo\nendobj\n";
        }

        $inicioXref = strlen($pdf);
        $n = count($objs) + 1;
        $pdf .= "xref\n0 $n\n0000000000 65535 f \n";
        foreach ($desviaciones as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size $n /Root 1 0 R >>\nstartxref\n$inicioXref\n%%EOF";

        return $pdf;
    }

    public function descargar(string $nombreArchivo, bool $enLinea = false): never
    {
        $bin = $this->generar();
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . $nombreArchivo . '"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
}
