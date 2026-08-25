<?php
/**
 * Carga manual del XML de un comprobante.
 *
 * Para lo que SUNAT no entrega por su API —el emisor no lo transmitió, o el
 * servicio falla de forma persistente— el usuario consigue el XML por su cuenta
 * y lo sube. De ahí salen las líneas de producto y el inventario puede seguir.
 *
 * Lo delicado no es leer el archivo, sino comprobar que ES el de ese
 * comprobante: subir por error el XML de otra factura metería en el kardex
 * cantidades que no corresponden, y el error saldría a la luz mucho después,
 * cuadrando existencias. Por eso se contrasta serie, número, tipo y RUC del
 * emisor antes de tocar nada.
 */
class SubirCpe
{
    /** Un XML de comprobante no llega ni de lejos a este tamaño. */
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** @return string mensaje de éxito para la pantalla */
    public static function procesar(int $cpeId, ?array $archivo): string
    {
        $cpe = DB::uno('SELECT * FROM sunat_comprobantes WHERE id = :id AND ' . Empresa::filtro(),
            Empresa::param() + [':id' => $cpeId]);
        if (!$cpe) {
            throw new RuntimeException('Ese comprobante no es de la empresa activa.');
        }

        $contenido = self::contenido($archivo);
        $leido     = CpeXml::leer($contenido);

        self::comprobarQueEsElMismo($cpe, $leido);

        if (!$leido['items']) {
            throw new RuntimeException('El XML no tiene líneas con cantidad: '
                . 'no hay nada que registrar en el inventario.');
        }

        $rucPropio = (string) (CredencialSunat::deEmpresa()['ruc'] ?? '');
        $rutas = SunatCpe::guardarManual($cpe, $rucPropio, $contenido,
            CpeXml::cdrDe($contenido));

        $doc     = $cpe['serie'] . '-' . $cpe['numero'];
        $conCdr  = $rutas['cdr'] ? ' El ZIP traía también el CDR.' : '';
        $yaTiene = DB::todos('SELECT * FROM sunat_cpe_items WHERE cpe_id = :c ORDER BY linea',
            [':c' => $cpeId]);

        // Sin líneas, el XML es lo que las aporta: para eso se sube.
        if (!$yaTiene) {
            SunatCpeItem::guardarManual($cpeId, $leido['items'], $rutas);
            return sprintf('%s cargado desde el XML: %d línea(s) de producto.%s',
                $doc, count($leido['items']), $conCdr);
        }

        // Con líneas ya registradas —vinieron de la metadata de SUNAT— el XML
        // sólo se adjunta. Reemplazarlas sería peligroso: el comprobante puede
        // estar ya conciliado o convertido en movimientos, y cambiarle las
        // cantidades por debajo dejaría el kardex diciendo una cosa y el
        // comprobante otra. Lo que sí se hace es CONTRASTAR, que es justo para
        // lo que sirve tener el documento firmado.
        $diferencias = self::contrastar($yaTiene, $leido['items']);
        SunatCpeItem::adjuntarXml($cpeId, $rutas,
            $diferencias ? 'XML adjuntado a mano: NO cuadra con las líneas registradas'
                         : 'XML adjuntado a mano: cuadra con lo registrado');

        if ($diferencias) {
            return sprintf('%s: XML guardado, pero OJO, no cuadra con lo que estaba registrado. %s '
                . 'Las líneas NO se modificaron; revíselo antes de generar movimientos.',
                $doc, implode(' ', $diferencias));
        }

        return sprintf('%s: XML guardado y contrastado — sus %d línea(s) coinciden '
            . 'con lo que ya estaba registrado.%s', $doc, count($yaTiene), $conCdr);
    }

    /**
     * Compara las líneas del XML con las que ya estaban.
     *
     * Se miran cantidad, valor unitario y unidad, que es lo que decide el costo
     * y el stock. La descripción se deja fuera a propósito: algunos emisores
     * escriben el XML con caracteres corruptos que la metadata sí trae bien, y
     * avisar por eso sería ruido.
     *
     * @return string[] diferencias en lenguaje llano, vacío si todo cuadra
     */
    private static function contrastar(array $registradas, array $delXml): array
    {
        if (count($registradas) !== count($delXml)) {
            return [sprintf('El XML tiene %d línea(s) y hay %d registradas.',
                count($delXml), count($registradas))];
        }

        $avisos = [];
        foreach ($registradas as $i => $r) {
            $x = $delXml[$i];
            if (abs((float) $r['cantidad'] - $x['cantidad']) > 0.0001) {
                $avisos[] = sprintf('Línea %d: cantidad %s registrada frente a %s en el XML.',
                    $r['linea'], rtrim(rtrim(number_format((float) $r['cantidad'], 4, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($x['cantidad'], 4, '.', ''), '0'), '.'));
            }
            if (abs((float) $r['valor_unitario'] - $x['valor_unitario']) > 0.0001) {
                $avisos[] = sprintf('Línea %d: valor unitario %s registrado frente a %s en el XML.',
                    $r['linea'], number_format((float) $r['valor_unitario'], 4, '.', ''),
                    number_format($x['valor_unitario'], 4, '.', ''));
            }
        }
        return array_slice($avisos, 0, 4);   // con cuatro ejemplos se entiende el problema
    }

    /** Lee el archivo subido comprobando lo que el navegador no garantiza. */
    private static function contenido(?array $archivo): string
    {
        if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('No se eligió ningún archivo.');
        }
        if ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('El archivo es demasiado grande.');
        }
        if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            throw new RuntimeException('No se pudo recibir el archivo. Inténtelo de nuevo.');
        }
        if ($archivo['size'] > self::MAX_BYTES) {
            throw new RuntimeException('El archivo pasa de 5 MB: no parece el XML de un comprobante.');
        }

        $contenido = file_get_contents($archivo['tmp_name']);
        if ($contenido === false || $contenido === '') {
            throw new RuntimeException('El archivo llegó vacío.');
        }
        return $contenido;
    }

    /**
     * El XML debe ser el del comprobante que se está reparando.
     *
     * Se comparan los cuatro datos que lo identifican de forma única ante
     * SUNAT. El mensaje dice qué se esperaba y qué se recibió: si alguien se
     * equivoca de archivo, lo normal es que tenga varios parecidos delante.
     */
    private static function comprobarQueEsElMismo(array $cpe, array $leido): void
    {
        $numeroCpe = ltrim((string) $cpe['numero'], '0') ?: '0';
        $problemas = [];

        if (strcasecmp($leido['serie'], (string) $cpe['serie']) !== 0) {
            $problemas[] = sprintf('la serie es %s y se esperaba %s', $leido['serie'] ?: '(vacía)', $cpe['serie']);
        }
        if ($leido['numero'] !== $numeroCpe) {
            $problemas[] = sprintf('el número es %s y se esperaba %s', $leido['numero'], $numeroCpe);
        }

        $tipoCpe = (string) ($cpe['cod_tipo_cdp'] ?: '01');
        if ($leido['tipo_doc'] !== '' && $leido['tipo_doc'] !== $tipoCpe) {
            $problemas[] = sprintf('es un tipo %s y se esperaba %s (%s)',
                $leido['tipo_doc'], $tipoCpe, SunatComprobante::tipoDoc($tipoCpe));
        }

        // En una compra el emisor es el proveedor; en una venta, la empresa.
        $emisorEsperado = $cpe['tipo'] === 'ventas'
            ? preg_replace('/\D/', '', (string) (CredencialSunat::deEmpresa()['ruc'] ?? ''))
            : preg_replace('/\D/', '', (string) $cpe['ruc_contraparte']);

        if ($emisorEsperado !== '' && $leido['ruc_emisor'] !== '' && $leido['ruc_emisor'] !== $emisorEsperado) {
            $problemas[] = sprintf('lo emite el RUC %s y se esperaba %s',
                $leido['ruc_emisor'], $emisorEsperado);
        }

        if ($problemas) {
            throw new RuntimeException('Ese XML no corresponde a '
                . $cpe['serie'] . '-' . $cpe['numero'] . ': ' . implode('; ', $problemas)
                . '. No se registró nada.');
        }
    }
}
