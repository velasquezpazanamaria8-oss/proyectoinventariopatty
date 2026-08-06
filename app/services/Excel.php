<?php
/**
 * Generador de archivos .xlsx (Excel real, no CSV disfrazado). RF-12, RF-14.
 *
 * Escribe el paquete OpenXML a mano y lo empaqueta con Zip. Sin Composer,
 * sin PhpSpreadsheet. Soporta encabezado con estilo, anchos de columna,
 * números con formato, fechas, panel congelado, autofiltro y fila de totales.
 *
 * Uso:
 *   $x = new Excel('Kardex');
 *   $x->titulo('Kardex por producto', 'Empresa Demo S.A.C.');
 *   $x->columnas([['Código', 14, 'texto'], ['Cantidad', 12, 'numero']]);
 *   $x->fila(['P001', 12.5]);
 *   $x->descargar('kardex.xlsx');
 */
class Excel
{
    private string $hoja;
    private array $columnas = [];   // [titulo, ancho, tipo]
    private array $filas    = [];   // [['v'=>..., 'estilo'=>int], ...]
    private array $encabezado = []; // líneas de título previas a la tabla
    private array $compartidas = [];
    private int   $totalCompartidas = 0;

    // Índices de estilo definidos en styles.xml (ver estilosXml()).
    private const E_NORMAL   = 0;
    private const E_TITULO   = 1;
    private const E_SUBTIT   = 2;
    private const E_CABECERA = 3;
    private const E_NUMERO   = 4;
    private const E_TEXTO    = 5;
    private const E_TOTAL    = 6;
    private const E_TOTALNUM = 7;
    private const E_FECHA    = 8;

    public function __construct(string $hoja = 'Hoja1')
    {
        // Excel limita el nombre de hoja a 31 caracteres y prohíbe : \ / ? * [ ]
        $this->hoja = mb_substr(preg_replace('/[:\\\\\/?*\[\]]/u', '', $hoja), 0, 31) ?: 'Hoja1';
    }

    /** Líneas de título sobre la tabla. */
    public function titulo(string $titulo, string $subtitulo = ''): void
    {
        $this->encabezado[] = [$titulo, self::E_TITULO];
        if ($subtitulo !== '') {
            $this->encabezado[] = [$subtitulo, self::E_SUBTIT];
        }
    }

    public function linea(string $texto): void
    {
        $this->encabezado[] = [$texto, self::E_SUBTIT];
    }

    /**
     * Define las columnas.
     * @param array $cols cada una: [titulo, ancho, tipo] con tipo texto|numero|fecha
     */
    public function columnas(array $cols): void
    {
        $this->columnas = array_map(function ($c) {
            return is_array($c)
                ? ['titulo' => $c[0], 'ancho' => $c[1] ?? 16, 'tipo' => $c[2] ?? 'texto']
                : ['titulo' => $c, 'ancho' => 16, 'tipo' => 'texto'];
        }, $cols);
    }

    public function fila(array $valores): void
    {
        $this->filas[] = ['valores' => $valores, 'total' => false];
    }

    /** Fila de totales, en negrita y con borde superior. */
    public function filaTotal(array $valores): void
    {
        $this->filas[] = ['valores' => $valores, 'total' => true];
    }

    // ------------------------------------------------------------------

    public function generar(): string
    {
        // La hoja se arma PRIMERO: al construirla se puebla la tabla de
        // cadenas compartidas, que sólo entonces puede serializarse.
        $hoja = $this->hojaXml();

        $zip = new Zip();
        $zip->agregar('[Content_Types].xml', $this->contentTypes());
        $zip->agregar('_rels/.rels', $this->relsRaiz());
        $zip->agregar('xl/workbook.xml', $this->workbookXml());
        $zip->agregar('xl/_rels/workbook.xml.rels', $this->relsWorkbook());
        $zip->agregar('xl/styles.xml', $this->estilosXml());
        $zip->agregar('xl/sharedStrings.xml', $this->cadenasXml());
        $zip->agregar('xl/worksheets/sheet1.xml', $hoja);
        return $zip->generar();
    }

    public function descargar(string $nombreArchivo): never
    {
        $bin = $this->generar();
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: max-age=0');
        echo $bin;
        exit;
    }

    // --- Partes del paquete -------------------------------------------

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>';
    }

    private function relsRaiz(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    }

    private function relsWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . self::esc($this->hoja) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
    }

    private function estilosXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2">'
        .   '<numFmt numFmtId="164" formatCode="#,##0.00"/>'
        .   '<numFmt numFmtId="165" formatCode="dd/mm/yyyy"/>'
        . '</numFmts>'
        . '<fonts count="5">'
        .   '<font><sz val="10"/><name val="Calibri"/></font>'                                     // 0 normal
        .   '<font><b/><sz val="14"/><color rgb="FF12395B"/><name val="Calibri"/></font>'          // 1 título
        .   '<font><sz val="9"/><color rgb="FF64748B"/><name val="Calibri"/></font>'               // 2 subtítulo
        .   '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'          // 3 cabecera
        .   '<font><b/><sz val="10"/><name val="Calibri"/></font>'                                 // 4 total
        . '</fonts>'
        . '<fills count="3">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF1D5F92"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="3">'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
        .   '<border><left/><right/><top/><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>'
        .   '<border><left/><right/><top style="thin"><color rgb="FF12395B"/></top><bottom style="double"><color rgb="FF12395B"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                        // 0 normal
        .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                          // 1 título
        .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                          // 2 subtítulo
        .   '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 3 cabecera
        .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'                 // 4 número
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'                                        // 5 texto
        .   '<xf numFmtId="0" fontId="4" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1"/>'                          // 6 total texto
        .   '<xf numFmtId="164" fontId="4" fillId="0" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"/>'  // 7 total número
        .   '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'                // 8 fecha
        . '</cellXfs>'
        . '</styleSheet>';
    }

    /** Índice de cadena compartida (deduplicado). */
    private function idCadena(string $texto): int
    {
        $this->totalCompartidas++;
        if (!array_key_exists($texto, $this->compartidas)) {
            $this->compartidas[$texto] = count($this->compartidas);
        }
        return $this->compartidas[$texto];
    }

    private function hojaXml(): string
    {
        $nCols = max(1, count($this->columnas));
        $filas = '';
        $r     = 0;

        // Encabezado
        foreach ($this->encabezado as $linea) {
            $r++;
            $filas .= '<row r="' . $r . '">'
                   . $this->celdaTexto('A' . $r, $linea[0], $linea[1])
                   . '</row>';
        }
        if ($this->encabezado) { $r++; }        // fila en blanco de separación

        // Cabecera de la tabla
        $filaCabecera = 0;
        if ($this->columnas) {
            $r++;
            $filaCabecera = $r;
            $celdas = '';
            foreach ($this->columnas as $i => $c) {
                $celdas .= $this->celdaTexto(self::col($i) . $r, $c['titulo'], self::E_CABECERA);
            }
            $filas .= '<row r="' . $r . '" ht="26" customHeight="1">' . $celdas . '</row>';
        }

        // Datos
        foreach ($this->filas as $f) {
            $r++;
            $celdas = '';
            foreach (array_values($f['valores']) as $i => $v) {
                $tipo = $this->columnas[$i]['tipo'] ?? 'texto';
                $ref  = self::col($i) . $r;

                if ($v === null || $v === '') {
                    $celdas .= '<c r="' . $ref . '" s="' . ($f['total'] ? self::E_TOTAL : self::E_TEXTO) . '"/>';
                    continue;
                }
                if ($tipo === 'numero' && is_numeric($v)) {
                    $estilo  = $f['total'] ? self::E_TOTALNUM : self::E_NUMERO;
                    $celdas .= '<c r="' . $ref . '" s="' . $estilo . '"><v>' . (0 + $v) . '</v></c>';
                } elseif ($tipo === 'fecha' && $v !== '') {
                    $celdas .= '<c r="' . $ref . '" s="' . self::E_FECHA . '"><v>' . self::serieFecha((string) $v) . '</v></c>';
                } else {
                    $estilo  = $f['total'] ? self::E_TOTAL : self::E_TEXTO;
                    $celdas .= $this->celdaTexto($ref, (string) $v, $estilo);
                }
            }
            $filas .= '<row r="' . $r . '">' . $celdas . '</row>';
        }

        // Anchos de columna
        $cols = '';
        if ($this->columnas) {
            $cols = '<cols>';
            foreach ($this->columnas as $i => $c) {
                $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $c['ancho'] . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        // Panel congelado bajo la cabecera + autofiltro
        $panel = '';
        $filtro = '';
        if ($filaCabecera) {
            $panel = '<sheetView workbookViewId="0"><pane ySplit="' . $filaCabecera . '" topLeftCell="A' . ($filaCabecera + 1)
                   . '" activePane="bottomLeft" state="frozen"/></sheetView>';
            if ($this->filas) {
                $filtro = '<autoFilter ref="A' . $filaCabecera . ':' . self::col($nCols - 1) . $r . '"/>';
            }
        } else {
            $panel = '<sheetView workbookViewId="0"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews>' . $panel . '</sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . $cols
        . '<sheetData>' . $filas . '</sheetData>'
        . $filtro
        . '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '</worksheet>';
    }

    private function celdaTexto(string $ref, string $texto, int $estilo): string
    {
        return '<c r="' . $ref . '" s="' . $estilo . '" t="s"><v>' . $this->idCadena($texto) . '</v></c>';
    }

    private function cadenasXml(): string
    {
        $xml = '';
        foreach (array_keys($this->compartidas) as $cadena) {
            $xml .= '<si><t xml:space="preserve">' . self::esc((string) $cadena) . '</t></si>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
        . $this->totalCompartidas . '" uniqueCount="' . count($this->compartidas) . '">'
        . $xml . '</sst>';
    }

    /** Índice de columna a letra: 0 => A, 26 => AA. */
    private static function col(int $i): string
    {
        $letras = '';
        $i++;
        while ($i > 0) {
            $resto  = ($i - 1) % 26;
            $letras = chr(65 + $resto) . $letras;
            $i      = (int) (($i - $resto - 1) / 26);
        }
        return $letras;
    }

    /** Fecha a número de serie de Excel (base 1900). */
    private static function serieFecha(string $fecha): int
    {
        $ts = strtotime($fecha);
        if ($ts === false) return 0;
        // 25569 = días entre 1900-01-01 y la época Unix, corrigiendo el bug de 1900.
        return (int) floor($ts / 86400) + 25569;
    }

    private static function esc(string $s): string
    {
        // Excel rechaza los caracteres de control salvo tab, LF y CR.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
