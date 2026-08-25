<?php
/**
 * Lector de comprobantes electrónicos en XML (UBL 2.1 de SUNAT).
 *
 * Existe para los comprobantes que la API de SUNAT no entrega —el emisor no los
 * transmitió, o su servicio falla de forma persistente—. En esos casos el
 * usuario consigue el XML por su cuenta (del portal de SUNAT o pidiéndoselo al
 * proveedor) y lo sube aquí: de él salen las LÍNEAS DE PRODUCTO, que es lo único
 * que el SIRE no entrega y el inventario necesita para mover stock.
 *
 * Se lee con local-name() en lugar de registrar espacios de nombres porque los
 * prefijos varían entre emisores y facturadores; comparar sólo el nombre local
 * funciona con todos y evita rechazar un XML válido por venir con otro prefijo.
 */
class CpeXml
{
    /** Tipo de comprobante según la raíz del documento. */
    private const RAICES = [
        // En las facturas el código real viene en cbc:InvoiceTypeCode,
        // porque la misma raíz sirve para factura (01) y boleta (03).
        'Invoice'    => null,
        'CreditNote' => '07',
        'DebitNote'  => '08',
    ];

    /**
     * Lee un XML —o un ZIP que lo contenga— y devuelve cabecera y líneas.
     *
     * @throws RuntimeException si el contenido no es un comprobante legible.
     */
    public static function leer(string $contenido): array
    {
        $xml = self::extraer($contenido);

        $doc = new DOMDocument();
        // El archivo viene de fuera, así que se carga sin red ni entidades
        // externas: un XML manipulado no debe poder leer ficheros del servidor.
        $antes = libxml_use_internal_errors(true);
        $ok = $doc->loadXML(self::normalizar($xml), LIBXML_NONET);
        libxml_use_internal_errors($antes);

        if (!$ok || !$doc->documentElement) {
            throw new RuntimeException('El archivo no es un XML válido.');
        }

        $raiz = $doc->documentElement->localName;
        if (!array_key_exists($raiz, self::RAICES)) {
            // El CDR (ApplicationResponse) es la confusión típica: se parece,
            // pero no trae líneas, y aceptarlo dejaría el comprobante en cero.
            throw new RuntimeException($raiz === 'ApplicationResponse'
                ? 'Ese archivo es el CDR (la constancia de SUNAT), no el comprobante. Suba el XML de la factura.'
                : 'El XML no es una factura, boleta ni nota de crédito o débito (raíz: ' . $raiz . ').');
        }

        $x = new DOMXPath($doc);

        $id = self::texto($x, '/*/' . self::n('ID'));
        $partes = explode('-', $id, 2);

        $cab = [
            'tipo_doc'     => self::RAICES[$raiz] ?? self::texto($x, '/*/' . self::n('InvoiceTypeCode')),
            'serie'        => strtoupper(trim($partes[0] ?? '')),
            'numero'       => ltrim(trim($partes[1] ?? ''), '0') ?: '0',
            'fecha'        => self::texto($x, '/*/' . self::n('IssueDate')),
            'moneda'       => self::texto($x, '/*/' . self::n('DocumentCurrencyCode')),
            'ruc_emisor'   => self::ruc($x, 'AccountingSupplierParty'),
            'ruc_receptor' => self::ruc($x, 'AccountingCustomerParty'),
            'total'        => (float) self::texto($x,
                '/*/' . self::n('LegalMonetaryTotal') . '/' . self::n('PayableAmount')),
        ];

        $cab['items'] = self::lineas($x);
        return $cab;
    }

    /**
     * Arregla la codificación antes de interpretar el XML.
     *
     * Muchos facturadores peruanos declaran ISO-8859-1 en la cabecera pero
     * escriben el contenido en UTF-8. Si se hace caso a la declaración, cada
     * tilde o eñe se convierte en basura y la descripción del producto llega
     * ilegible al catálogo. Se decide por los bytes, que no mienten, y no por
     * lo que el archivo dice de sí mismo.
     */
    private static function normalizar(string $xml): string
    {
        // Un BOM delante de la declaración hace fallar al analizador.
        $bom = "\xEF\xBB\xBF";
        if (str_starts_with($xml, $bom)) {
            $xml = substr($xml, strlen($bom));
        }

        $patron = '/(<\?xml[^>]*encoding=["\'])([^"\']+)(["\'])/i';
        preg_match($patron, $xml, $m);
        $declarada = strtoupper($m[2] ?? '');
        $esUtf8    = mb_check_encoding($xml, 'UTF-8');

        // Dice una cosa y contiene otra: mandan los bytes.
        if ($declarada !== '' && $declarada !== 'UTF-8' && $esUtf8) {
            return preg_replace($patron, '${1}UTF-8${3}', $xml, 1);
        }

        // Al revés: se declara UTF-8 pero son bytes de Windows.
        if (($declarada === 'UTF-8' || $declarada === '') && !$esUtf8) {
            $convertido = mb_convert_encoding($xml, 'UTF-8', 'Windows-1252');
            return $declarada === ''
                ? $convertido
                : preg_replace($patron, '${1}UTF-8${3}', $convertido, 1);
        }

        return $xml;
    }

    /** Un ZIP puede traer el comprobante y su CDR; aquí se busca el comprobante. */
    private static function extraer(string $contenido): string
    {
        if (!str_starts_with($contenido, "PK\x03\x04")) {
            return $contenido;
        }
        foreach (ZipLector::entradas($contenido) as $nombre => $datos) {
            $base = basename($nombre);
            if ($datos !== '' && !str_starts_with($base, 'R-')
                && strtolower(pathinfo($base, PATHINFO_EXTENSION)) === 'xml') {
                return $datos;
            }
        }
        throw new RuntimeException('El ZIP no contiene el XML del comprobante.');
    }

    /** El CDR que venga dentro del mismo ZIP, si lo hay. */
    public static function cdrDe(string $contenido): ?string
    {
        if (!str_starts_with($contenido, "PK\x03\x04")) {
            return null;
        }
        foreach (ZipLector::entradas($contenido) as $nombre => $datos) {
            if ($datos !== '' && str_starts_with(basename($nombre), 'R-')) {
                return $datos;
            }
        }
        return null;
    }

    /**
     * Líneas del comprobante.
     *
     * `cbc:PriceAmount` dentro de `cac:Price` es el valor unitario SIN IGV, que
     * es el que debe costear el inventario. No se toma `AlternativeConditionPrice`
     * porque ése es el precio de venta CON IGV, y el IGV es crédito fiscal, no
     * costo. Es el mismo criterio que aplica la descarga automática.
     */
    private static function lineas(DOMXPath $x): array
    {
        $nodos = $x->query('/*/*[local-name()="InvoiceLine"'
            . ' or local-name()="CreditNoteLine" or local-name()="DebitNoteLine"]');

        $out = [];
        $n = 0;
        foreach ($nodos as $nodo) {
            // La cantidad se llama distinto en cada tipo: InvoicedQuantity,
            // CreditedQuantity o DebitedQuantity. Se busca por la terminación.
            $nodoCant = $x->query(
                '*[substring(local-name(), string-length(local-name()) - 7) = "Quantity"]', $nodo)->item(0);

            $cantidad = $nodoCant ? (float) $nodoCant->textContent : 0.0;
            if ($cantidad == 0.0) {
                continue;                        // línea sin cantidad: no mueve stock
            }

            $unidad = $nodoCant instanceof DOMElement ? $nodoCant->getAttribute('unitCode') : '';
            $item   = $x->query('*[local-name()="Item"]', $nodo)->item(0);

            $out[] = [
                'linea'          => ++$n,
                'codigo_sunat'   => $item ? self::texto($x,
                    './/' . self::n('SellersItemIdentification') . '/' . self::n('ID'), $item) : '',
                'descripcion'    => $item ? self::texto($x, './/' . self::n('Description'), $item) : '',
                'cantidad'       => $cantidad,
                'unidad_codigo'  => trim($unidad),
                // El XML no trae el nombre de la unidad, sólo su código.
                'unidad_nombre'  => '',
                'valor_unitario' => (float) self::texto($x,
                    './/' . self::n('Price') . '/' . self::n('PriceAmount'), $nodo),
                'importe'        => (float) self::texto($x, self::n('LineExtensionAmount'), $nodo),
            ];
        }
        return $out;
    }

    private static function ruc(DOMXPath $x, string $parte): string
    {
        return preg_replace('/\D/', '', self::texto($x,
            '/*/' . self::n($parte) . '//' . self::n('PartyIdentification') . '/' . self::n('ID')));
    }

    /** Paso de XPath que compara sólo el nombre local del elemento. */
    private static function n(string $nombre): string
    {
        return '*[local-name()="' . $nombre . '"]';
    }

    private static function texto(DOMXPath $x, string $ruta, ?DOMNode $ctx = null): string
    {
        $r = $ctx ? $x->query($ruta, $ctx) : $x->query($ruta);
        return $r && $r->length ? trim($r->item(0)->textContent) : '';
    }
}
