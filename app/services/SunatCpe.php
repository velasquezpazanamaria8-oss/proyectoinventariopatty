<?php
/**
 * Descarga de comprobantes: metadata, XML y PDF, y extracción de las líneas
 * de producto (que es lo que después mueve el inventario).
 *
 * Confirmado contra SUNAT real el 2026-08-17 (RUC 20613129562):
 *
 *   referencia = {rucEmisor}-{tipoDoc}-{serie}-{correlativo sin ceros}
 *   origen     = 1 venta (la emitió la empresa) · 2 compra (la recibió)
 *                El 3 ("cualquiera") NO funciona: da 404.
 *
 *   metadata : GET /v1/contribuyente/consultacpe/comprobantes/{ref}-{origen}
 *   PDF      : .../{ref}-{origen}/01     XML: /02     CDR: /03
 *
 * Los archivos llegan como JSON con un ZIP en base64 dentro.
 *
 * Dos cosas medidas que conviene recordar:
 *  · La metadata trae `informacionItems` ya parseado, y con los acentos
 *    correctos. El XML de los mismos comprobantes los trae rotos
 *    ("FILTRO HIDR?ULICO"), así que para las descripciones se usa la
 *    metadata y el XML queda como respaldo.
 *  · El CDR responde 422 tanto en ventas como en compras. Se intenta, y si
 *    no está se anota; no se trata como fallo del comprobante.
 */
class SunatCpe
{
    private const RUTA = '/v1/contribuyente/consultacpe/comprobantes/';

    public const ARCHIVOS = ['pdf' => '01', 'xml' => '02', 'cdr' => '03'];

    /**
     * Descarga un comprobante completo y guarda todo.
     * @return array resumen de lo hecho para mostrarlo en pantalla
     */
    public static function descargar(array $cred, array $cpe): array
    {
        $ref    = self::referencia($cpe, $cred['ruc']);
        $origen = $cpe['tipo'] === 'ventas' ? '1' : '2';
        $token  = SunatApi::token($cred, SunatApi::SCOPE_CPE);

        $r = ['ref' => $ref, 'items' => 0, 'archivos' => [], 'faltan' => [], 'error' => null];

        // --- Metadata: de aquí salen las líneas de producto ---
        [$code, $body] = self::pedir(self::RUTA . "$ref-$origen", $token);

        if ($code !== 200) {
            $r['error'] = self::explicar($code, $body);
            return $r;
        }

        $meta = json_decode($body, true);
        $comp = $meta['comprobantes'][0] ?? null;
        if (!$comp) {
            $r['error'] = 'SUNAT respondió sin datos del comprobante.';
            return $r;
        }

        $items = self::items($comp);
        $r['items'] = count($items);

        // --- Archivos ---
        $dir = self::directorio($cpe['periodo']);
        foreach (self::ARCHIVOS as $clase => $sufijo) {
            [$c2, $b2] = self::pedir(self::RUTA . "$ref-$origen/$sufijo", $token, 45);

            if ($c2 !== 200) {
                // El CDR no está disponible por esta vía: es un estado normal,
                // no un fallo del comprobante.
                $r['faltan'][$clase] = $c2;
                continue;
            }
            $g = self::guardarArchivo($b2, $dir, "$ref-$clase", $clase);
            if ($g['ruta'] !== null) {
                $r['archivos'][$clase] = $g['ruta'];
            } else {
                $r['faltan'][$clase] = 'contenido vacío';
            }
            // El ZIP del XML suele traer el CDR dentro (R-*.xml). Es la única
            // vía por la que llega: pedirlo suelto (sufijo 03) responde 422.
            // Si no se anota aquí, el archivo queda en disco sin referencia y
            // la pantalla dice que no está descargado.
            if ($g['cdr'] !== null && !isset($r['archivos']['cdr'])) {
                $r['archivos']['cdr'] = $g['cdr'];
            }
        }

        if (isset($r['archivos']['cdr'])) {
            unset($r['faltan']['cdr']);      // llegó dentro del ZIP del XML
        }

        SunatCpeItem::guardar((int) $cpe['id'], $items, $r['archivos'], $r['faltan']);
        return $r;
    }

    /**
     * Guarda un XML conseguido a mano, con el mismo nombre y en la misma
     * carpeta que si lo hubiera bajado la API.
     *
     * Mantener el criterio de nombres en un solo sitio evita que los archivos
     * subidos queden en otro formato y luego no se encuentren.
     *
     * @return array{xml:string, cdr:?string} rutas relativas a la raíz
     */
    public static function guardarManual(array $cpe, string $rucPropio, string $xml, ?string $cdr): array
    {
        $dir = self::directorio($cpe['periodo']);
        $ref = self::referencia($cpe, $rucPropio);

        $destino = $dir . '/' . $ref . '-xml.xml';
        file_put_contents($destino, $xml);
        $rutas = ['xml' => self::relativa($destino), 'cdr' => null];

        if ($cdr !== null && $cdr !== '') {
            $destinoCdr = $dir . '/' . $ref . '-xml-cdr.xml';
            file_put_contents($destinoCdr, $cdr);
            $rutas['cdr'] = self::relativa($destinoCdr);
        }
        return $rutas;
    }

    /** Referencia que espera SUNAT. El emisor es el proveedor si es compra. */
    public static function referencia(array $cpe, string $rucPropio): string
    {
        $emisor = $cpe['tipo'] === 'ventas'
            ? preg_replace('/\D/', '', $rucPropio)
            : preg_replace('/\D/', '', (string) $cpe['ruc_contraparte']);

        return sprintf('%s-%s-%s-%s',
            $emisor,
            $cpe['cod_tipo_cdp'] ?: '01',
            $cpe['serie'],
            ltrim((string) $cpe['numero'], '0') ?: '0');   // sin ceros a la izquierda
    }

    /**
     * Normaliza las líneas de la metadata.
     * `mtoValUnitario` es el valor unitario SIN IGV — comprobado contra el
     * `LineExtensionAmount` del XML del mismo comprobante. Es el que debe
     * costear el inventario: el IGV es crédito fiscal, no costo.
     */
    private static function items(array $comp): array
    {
        $out = [];
        foreach ((array) ($comp['informacionItems'] ?? []) as $i => $it) {
            $cantidad = (float) ($it['cntItems'] ?? 0);
            if ($cantidad == 0.0) {
                continue;                       // línea sin cantidad: no mueve stock
            }
            $out[] = [
                'linea'          => $i + 1,
                'codigo_sunat'   => trim((string) ($it['desCodigo'] ?? '')),
                'descripcion'    => trim((string) ($it['desItem'] ?? '')),
                'cantidad'       => $cantidad,
                'unidad_codigo'  => trim((string) ($it['codUnidadMedida'] ?? '')),
                'unidad_nombre'  => trim((string) ($it['desUnidadMedida'] ?? '')),
                'valor_unitario' => (float) ($it['mtoValUnitario'] ?? 0),
                'importe'        => (float) ($it['mtoImpTotal'] ?? 0),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Archivos
    // ------------------------------------------------------------------

    /** storage/cpe/{empresa}/{periodo}/ — fuera del alcance web por .htaccess. */
    private static function directorio(string $periodo): string
    {
        $dir = BASE_PATH . '/storage/cpe/' . Empresa::id() . '/' . preg_replace('/\D/', '', $periodo);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Guarda el archivo que viene en base64. El contenido suele ser un ZIP:
     * se detecta POR FIRMA, no por el nombre, y se extrae lo de dentro.
     *
     * @return array{ruta:?string,cdr:?string} ruta relativa del archivo de esta
     *         clase y, si el ZIP lo traía dentro, la del CDR.
     */
    private static function guardarArchivo(string $json, string $dir, string $nombre, string $clase): array
    {
        $vacio = ['ruta' => null, 'cdr' => null];

        $j = json_decode($json, true);
        $b64 = (string) ($j['valArchivo'] ?? '');
        if ($b64 === '') {
            return $vacio;
        }
        $bin = base64_decode(strtr($b64, '-_', '+/'), false);
        if ($bin === false || $bin === '') {
            return $vacio;
        }

        if (str_starts_with($bin, "PK\x03\x04")) {
            $entradas = ZipLector::entradas($bin);
            // El ZIP del XML de una compra puede traer también el CDR (R-*.xml).
            $guardado = null;
            $cdr = null;
            $n = 0;
            foreach ($entradas as $nom => $datos) {
                if ($datos === '') continue;
                $esCdr = str_starts_with(basename($nom), 'R-');

                // Un sufijo por entrada: sin él, dos entradas de la misma clase
                // se escriben sobre el mismo archivo y sólo sobrevive la última.
                $sufijo = $esCdr ? '-cdr' : ($n++ ? '-' . $n : '');
                $destino = $dir . '/' . $nombre . $sufijo . '.' . self::extension($nom, $clase);
                file_put_contents($destino, $datos);

                if ($esCdr) {
                    $cdr = $cdr ?? self::relativa($destino);
                } else {
                    $guardado = $guardado ?? self::relativa($destino);
                }
            }
            return ['ruta' => $guardado, 'cdr' => $cdr];
        }

        // No es ZIP: se guarda tal cual (los PDF a veces llegan sueltos).
        $destino = $dir . '/' . $nombre . '.' . ($clase === 'pdf' ? 'pdf' : 'xml');
        file_put_contents($destino, $bin);
        return ['ruta' => self::relativa($destino), 'cdr' => null];
    }

    private static function extension(string $nombre, string $clase): string
    {
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        return in_array($ext, ['xml', 'pdf'], true) ? $ext : ($clase === 'pdf' ? 'pdf' : 'xml');
    }

    private static function relativa(string $absoluta): string
    {
        return ltrim(str_replace('\\', '/', substr($absoluta, strlen(BASE_PATH))), '/');
    }

    // ------------------------------------------------------------------

    /** GET con reintentos: SUNAT devuelve ~8% de 500 transitorios. */
    private static function pedir(string $ruta, string $token, int $timeout = 30): array
    {
        $espera = 2;
        $ultimo = [0, ''];

        for ($i = 1; $i <= 3; $i++) {
            $ultimo = SunatApi::getCpe($ruta, $token, $timeout);
            if (!SunatApi::esTransitorio($ultimo[0])) {
                return $ultimo;
            }
            if ($i < 3) {
                sleep($espera);
                $espera *= 2;
            }
        }
        return $ultimo;
    }

    /**
     * Traduce el código a algo accionable. Distinguir "SUNAT no lo tiene" de
     * "SUNAT falló" es lo que evita marcar como perdido un comprobante que sí
     * existe (la trampa nº2 de la guía).
     */
    private static function explicar(int $code, string $body): string
    {
        return match (true) {
            $code === 404 => 'SUNAT no tiene ese comprobante (puede que el emisor aún no lo transmita).',
            $code === 422 => 'SUNAT rechazó los parámetros: revisar el código de origen (venta/compra).',
            $code === 401 || $code === 403 => 'El token no tiene habilitado consultacpe.',
            $code === 0   => 'SUNAT no respondió tras varios intentos (transitorio, se puede reintentar).',
            $code >= 500  => "SUNAT devolvió un error temporal (HTTP $code): se puede reintentar.",
            default       => "SUNAT respondió HTTP $code: " . mb_substr(trim($body), 0, 120),
        };
    }
}
