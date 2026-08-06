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
        $this->cabecera();
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
        $txt = $this->recortar($txt, $ancho - 8, $tam);
        $w   = $this->anchoTexto($txt, $tam);

        $px = match ($alin) {
            'der'    => $x + $ancho - 4 - $w,
            'centro' => $x + ($ancho - $w) / 2,
            default  => $x + 4,
        };

        $this->actual .= sprintf("BT %s rg /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $color, $fuente, $tam, $px, $y, self::escapar($txt));
    }

    /** Ancho aproximado en puntos (Helvetica ≈ 0.5 em por carácter). */
    private function anchoTexto(string $txt, float $tam): float
    {
        return strlen($txt) * $tam * 0.5;
    }

    private function recortar(string $txt, float $ancho, float $tam): string
    {
        $txt = self::aLatin($txt);
        $max = (int) max(1, floor($ancho / ($tam * 0.5)));
        if (strlen($txt) <= $max) {
            return $txt;
        }
        return substr($txt, 0, max(1, $max - 1)) . '.';
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
                . "/Resources << /Font << /%s 3 0 R /%s 4 0 R >> >> /Contents %d 0 R >>",
                $this->ancho, $this->alto, self::FUENTE, self::NEGRITA, $idCnt);

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
