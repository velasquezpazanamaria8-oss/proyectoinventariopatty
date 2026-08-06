<?php
/**
 * Lector de hojas de cálculo para la importación masiva.
 *
 * Acepta .xlsx (OpenXML, leído con ZipLector) y .csv / .txt con separador
 * autodetectado. Devuelve las filas indexadas por su NÚMERO REAL en el
 * archivo —no por posición— para que los errores que se le muestran al
 * usuario apunten a la fila que ve en Excel aunque haya filas vacías.
 * Cada fila es un arreglo de celdas en texto, con las columnas vacías
 * intermedias rellenadas.
 */
class ExcelLector
{
    /** Lee un archivo y devuelve sus filas indexadas por número de fila. */
    public static function leer(string $ruta, string $nombreOriginal = ''): array
    {
        $ext = strtolower(pathinfo($nombreOriginal ?: $ruta, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'txt') {
            return self::leerCsv($ruta);
        }
        if ($ext === 'xlsx' || $ext === 'xlsm') {
            return self::leerXlsx($ruta);
        }
        if ($ext === 'xls') {
            throw new RuntimeException(
                'El formato .xls (Excel 97-2003) no está soportado. '
                . 'Abra el archivo en Excel y guárdelo como .xlsx o .csv.');
        }
        throw new RuntimeException('Formato no reconocido. Use .xlsx o .csv.');
    }

    // --- CSV -----------------------------------------------------------

    private static function leerCsv(string $ruta): array
    {
        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }
        $contenido = self::aUtf8($contenido);

        // Excel en español suele exportar con punto y coma.
        $muestra = substr($contenido, 0, 4000);
        $sep = substr_count($muestra, ';') > substr_count($muestra, ',') ? ';' : ',';

        $filas = [];
        $n = 0;
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $contenido);
        rewind($fh);
        while (($f = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
            $n++;
            if ($f === [null] || (count($f) === 1 && trim((string) $f[0]) === '')) {
                continue;   // línea en blanco
            }
            $filas[$n] = array_map(fn($c) => trim((string) $c), $f);
        }
        fclose($fh);
        return $filas;
    }

    /** Normaliza a UTF-8 quitando el BOM si lo hubiera. */
    private static function aUtf8(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            return substr($s, 3);
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        return $s;
    }

    // --- XLSX ----------------------------------------------------------

    private static function leerXlsx(string $ruta): array
    {
        $zip = ZipLector::desdeArchivo($ruta);

        // Cadenas compartidas (pueden venir fragmentadas en varios <t>)
        $sst = [];
        if ($zip->existe('xl/sharedStrings.xml')) {
            $xml = self::xml($zip->leer('xl/sharedStrings.xml'));
            foreach ($xml->si as $si) {
                $sst[] = self::textoDeSi($si);
            }
        }

        $hoja = self::rutaPrimeraHoja($zip);
        $xml  = self::xml($zip->leer($hoja));

        $filas = [];
        $auto  = 0;
        foreach ($xml->sheetData->row as $row) {
            $auto++;
            $numeroFila = (int) ($row['r'] ?? 0) ?: $auto;
            $celdas = [];
            $maxCol = -1;

            foreach ($row->c as $c) {
                $ref  = (string) $c['r'];
                $idx  = self::indiceColumna($ref);
                $tipo = (string) $c['t'];

                if ($tipo === 'inlineStr') {
                    $valor = self::textoDeSi($c->is);
                } else {
                    $v = isset($c->v) ? (string) $c->v : '';
                    if ($tipo === 's') {
                        $valor = $sst[(int) $v] ?? '';
                    } elseif ($tipo === 'b') {
                        $valor = $v === '1' ? '1' : '0';
                    } elseif ($tipo === 'e') {
                        $valor = '';            // celda con error (#N/A, #REF!)
                    } else {
                        $valor = $v;
                    }
                }

                $celdas[$idx] = trim((string) $valor);
                $maxCol = max($maxCol, $idx);
            }

            if ($maxCol < 0) {
                continue;
            }
            // Rellena huecos para que los índices coincidan con las columnas
            $completa = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $completa[$i] = $celdas[$i] ?? '';
            }
            if (implode('', $completa) === '') {
                continue;   // fila totalmente vacía
            }
            $filas[$numeroFila] = $completa;
        }
        return $filas;
    }

    /** Texto de un nodo <si> o <is>, uniendo sus fragmentos <t>. */
    private static function textoDeSi($nodo): string
    {
        if ($nodo === null) return '';
        $txt = '';
        if (isset($nodo->t)) {
            foreach ($nodo->t as $t) { $txt .= (string) $t; }
        }
        if (isset($nodo->r)) {
            foreach ($nodo->r as $r) {
                if (isset($r->t)) { $txt .= (string) $r->t; }
            }
        }
        return $txt;
    }

    /** Ruta de la primera hoja según workbook.xml y sus relaciones. */
    private static function rutaPrimeraHoja(ZipLector $zip): string
    {
        $candidata = 'xl/worksheets/sheet1.xml';

        if ($zip->existe('xl/workbook.xml') && $zip->existe('xl/_rels/workbook.xml.rels')) {
            $wb = self::xml($zip->leer('xl/workbook.xml'));
            $rid = null;
            foreach ($wb->sheets->sheet as $s) {
                $attrs = $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $rid = (string) ($attrs['id'] ?? '');
                break;   // la primera hoja
            }
            if ($rid) {
                $rels = self::xml($zip->leer('xl/_rels/workbook.xml.rels'));
                foreach ($rels->Relationship as $r) {
                    if ((string) $r['Id'] === $rid) {
                        $destino = ltrim((string) $r['Target'], '/');
                        $candidata = str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino;
                        break;
                    }
                }
            }
        }

        if (!$zip->existe($candidata)) {
            foreach ($zip->archivos() as $a) {
                if (str_starts_with($a, 'xl/worksheets/') && str_ends_with($a, '.xml')) {
                    return $a;
                }
            }
            throw new RuntimeException('El archivo Excel no contiene ninguna hoja legible.');
        }
        return $candidata;
    }

    private static function xml(string $contenido): SimpleXMLElement
    {
        $previo = libxml_use_internal_errors(true);
        // LIBXML_NONET evita cualquier acceso a red al analizar el documento.
        $xml = simplexml_load_string($contenido, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        if ($xml === false) {
            throw new RuntimeException('El archivo Excel tiene un contenido XML inválido.');
        }
        return $xml;
    }

    /** "BC12" => 54 (índice de columna base 0). */
    private static function indiceColumna(string $ref): int
    {
        $letras = rtrim(preg_replace('/[0-9]/', '', $ref));
        if ($letras === '') return 0;
        $n = 0;
        foreach (str_split(strtoupper($letras)) as $l) {
            $n = $n * 26 + (ord($l) - 64);
        }
        return $n - 1;
    }
}
