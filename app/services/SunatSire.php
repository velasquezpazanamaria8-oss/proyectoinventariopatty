<?php
/**
 * SIRE: qué comprobantes declaró SUNAT en un período.
 *
 * Devuelve datos de CABECERA (RUC, serie, base, IGV, total). El detalle de
 * productos NO viene por aquí: para eso hay que bajar cada comprobante.
 *
 * Endpoints confirmados contra SUNAT real (2026-08-17, RUC 20613129562):
 *   codLibro 140000 = RVIE (ventas) · 080000 = RCE (compras)
 *
 * Trampas ya pagadas, documentadas en la guía de ContaDocs:
 *  · Un período sin comprobantes responde HTTP 422 (código 1070), no ceros.
 *    Eso es un período vacío, NO un fallo: tratarlo como error aborta la
 *    sincronización completa.
 *  · Ventas y compras traen fechas y nombres de campo DISTINTOS.
 *  · Las notas de crédito de ventas llevan el monto real en los campos de
 *    descuento: si no se suman, la NC no resta nada.
 *  · SUNAT devuelve ~8% de 500 transitorios: hay que reintentar.
 */
class SunatSire
{
    public const LIBRO_VENTAS  = '140000';   // RVIE
    public const LIBRO_COMPRAS = '080000';   // RCE

    private const BASE = '/v1/contribuyente/migeigv/libros';
    private const MAX_PAGINAS = 600;         // 60.000 comprobantes; tope de seguridad, no de negocio

    /** Períodos que SUNAT reconoce para el RUC, con su estado de declaración. */
    public static function periodos(array $cred): array
    {
        $tk = SunatApi::token($cred, SunatApi::SCOPE_SIRE);
        [$code, $body] = self::pedir(self::BASE . '/rvierce/padron/web/omisos/' . self::LIBRO_VENTAS . '/periodos', $tk);

        if ($code !== 200) {
            throw new RuntimeException("SUNAT no devolvió los períodos del SIRE (HTTP $code). "
                . 'Puede que el RUC aún no esté incorporado al SIRE.');
        }
        $out = [];
        foreach ((array) json_decode($body, true) as $ejercicio) {
            foreach ((array) ($ejercicio['lisPeriodos'] ?? []) as $p) {
                if (!empty($p['perTributario'])) {
                    $out[$p['perTributario']] = (string) ($p['desEstado'] ?? '');
                }
            }
        }
        krsort($out);
        return $out;
    }

    /**
     * Totales del período según SUNAT. Sirve para comprobar que el detalle
     * descargado cuadra: si no cuadra, casi siempre faltan los campos de
     * descuento de las notas de crédito.
     *
     * @return array{documentos:int,base:float,igv:float,total:float,vacio:bool}
     */
    public static function resumen(array $cred, string $periodo, string $libro): array
    {
        $tk = SunatApi::token($cred, SunatApi::SCOPE_SIRE);
        $per = preg_replace('/\D/', '', $periodo);

        [$code, $body] = self::pedir(
            self::BASE . "/rvierce/resumen/web/resumencomprobantes/$per/1/0/exporta?codLibro=$libro", $tk);

        $vacio = ['documentos' => 0, 'base' => 0.0, 'igv' => 0.0, 'total' => 0.0, 'vacio' => true];

        // 422 = el período no tiene comprobantes todavía. Es normal.
        if ($code === 422) {
            return $vacio;
        }
        if ($code !== 200 || $body === '' || $body[0] === '<') {
            throw new RuntimeException("SUNAT no entregó el resumen del período $per (HTTP $code).");
        }

        // Texto plano con columnas separadas por '|'. Interesa la fila TOTAL.
        $fila = null;
        foreach (preg_split('/\r?\n/', trim($body)) as $l) {
            if (stripos(ltrim($l), 'TOTAL') === 0) { $fila = explode('|', $l); break; }
        }
        if ($fila === null) {
            throw new RuntimeException('Formato inesperado del resumen del SIRE: no se encontró la fila TOTAL.');
        }

        $n = fn(int $i): float => (float) str_replace(',', '', trim($fila[$i] ?? '0'));

        if ($libro === self::LIBRO_VENTAS) {
            // base = BI gravada + descuento de BI ; igv = IGV + descuento de IGV
            $base = $n(3) + $n(4);
            $igv  = $n(5) + $n(6);
        } else {
            // compras: BI y de DG + DGNG + DNG
            $base = $n(2) + $n(4) + $n(6);
            $igv  = $n(3) + $n(5) + $n(7);
        }

        return [
            'documentos' => (int) $n(1),
            'base'  => round($base, 2),
            'igv'   => round($igv, 2),
            'total' => round((float) str_replace(',', '', trim(end($fila))), 2),
            'vacio' => false,
        ];
    }

    /**
     * Detalle del período, paginado y normalizado.
     * @param string $tipo 'ventas' | 'compras'
     */
    public static function comprobantes(array $cred, string $periodo, string $tipo): array
    {
        $tk  = SunatApi::token($cred, SunatApi::SCOPE_SIRE);
        $per = preg_replace('/\D/', '', $periodo);

        $out = [];
        $pagina = 1;
        $totalPaginas = 1;

        do {
            $ruta = $tipo === 'compras'
                ? self::BASE . "/rce/propuesta/web/propuesta/$per/busqueda?codTipoOpe=1&page=$pagina&perPage=100"
                : self::BASE . "/rvie/propuesta/web/propuesta/$per/comprobantes?page=$pagina&perPage=100";

            [$code, $body] = self::pedir($ruta, $tk, 30);

            if ($code === 422) {
                return $out;     // período sin comprobantes: no es error
            }
            if ($code !== 200) {
                throw new RuntimeException("SUNAT no entregó el detalle de $tipo del período $per (HTTP $code): "
                    . mb_substr(trim($body), 0, 200));
            }

            $j = json_decode($body, true);
            if (!is_array($j) || !array_key_exists('registros', $j)) {
                throw new RuntimeException("Formato inesperado del detalle de $tipo.");
            }
            foreach ((array) $j['registros'] as $r) {
                $out[] = self::normalizar($r, $tipo);
            }

            $totalPaginas = max(1, (int) ceil((int) ($j['paginacion']['totalRegistros'] ?? 0) / 100));
            $pagina++;
        } while ($pagina <= $totalPaginas && $pagina <= self::MAX_PAGINAS);

        if ($pagina > self::MAX_PAGINAS && $pagina <= $totalPaginas) {
            // Falla ruidosamente: cortar en silencio deja comprobantes fuera
            // sin que nadie lo note, que es peor que un error visible.
            throw new RuntimeException('El período supera el tope de ' . self::MAX_PAGINAS
                . ' páginas. Hay que revisarlo antes de continuar.');
        }
        return $out;
    }

    /**
     * Trae ventas y compras del período y las guarda. Devuelve el resumen de
     * lo sincronizado más el contraste con los totales de SUNAT.
     */
    public static function sincronizar(array $cred, string $periodo): array
    {
        $per = preg_replace('/\D/', '', $periodo);
        $r = ['periodo' => $per, 'tipos' => []];

        foreach (['ventas' => self::LIBRO_VENTAS, 'compras' => self::LIBRO_COMPRAS] as $tipo => $libro) {
            $detalle = self::comprobantes($cred, $per, $tipo);
            $resumen = self::resumen($cred, $per, $libro);

            SunatComprobante::guardarLote($per, $tipo, $detalle);

            // Cuántos quedaron REALMENTE en la base, no cuántos se intentaron.
            // Si la clave natural colapsa dos comprobantes distintos, aquí se
            // nota; contar los intentos lo ocultaría (pasó: 2 de 34 perdidos).
            $guardados = SunatComprobante::contar($per, $tipo);

            // Contraste: la suma del detalle debe cuadrar con la fila TOTAL.
            $sumaBase = round(array_sum(array_column($detalle, 'base_gravada')), 2);
            $sumaIgv  = round(array_sum(array_column($detalle, 'igv')), 2);

            $r['tipos'][$tipo] = [
                'descargados' => count($detalle),
                'guardados'   => $guardados,
                'sunat_docs'  => $resumen['documentos'],
                'sunat_base'  => $resumen['base'],
                'sunat_igv'   => $resumen['igv'],
                'suma_base'   => $sumaBase,
                'suma_igv'    => $sumaIgv,
                'cuadra'      => $resumen['vacio']
                    || (abs($sumaBase - $resumen['base']) < 1.0 && abs($sumaIgv - $resumen['igv']) < 1.0),
                'vacio'       => $resumen['vacio'],
            ];
        }

        Auditoria::registrar('SINCRONIZAR_SIRE', 'sunat_comprobantes', $per, $r['tipos']);
        return $r;
    }

    // ------------------------------------------------------------------

    /**
     * GET con reintentos. Cubre tanto los 500 transitorios de SUNAT (~8% de las
     * peticiones) como los timeouts, que llegan como código 0. Los tiempos de
     * respuesta de SUNAT varían mucho —se han medido de 2,5 s a 58 s para la
     * misma operación— así que el timeout es generoso.
     */
    private const INTENTOS = 4;

    private static function pedir(string $ruta, string $token, int $timeout = 45): array
    {
        $espera = 2;
        $ultimo = [0, ''];

        for ($intento = 1; $intento <= self::INTENTOS; $intento++) {
            $ultimo = SunatApi::getSire($ruta, $token, $timeout);

            if (!SunatApi::esTransitorio($ultimo[0])) {
                return $ultimo;
            }
            if ($intento < self::INTENTOS) {
                sleep($espera);
                $espera *= 2;      // 2s, 4s, 8s
            }
        }

        // Se agotaron los intentos: el mensaje dice qué pasó, para no
        // confundir "SUNAT no responde" con "el período no tiene datos".
        if ($ultimo[0] === 0) {
            throw new RuntimeException('SUNAT no respondió tras ' . self::INTENTOS
                . ' intentos. ' . mb_substr($ultimo[1], 0, 150));
        }
        return $ultimo;
    }

    /**
     * Ventas y compras vienen con formatos distintos: aquí se unifican.
     * Ver la tabla de diferencias en la guía; cada una costó horas de depuración.
     */
    private static function normalizar(array $r, string $tipo): array
    {
        // Fecha: compras YYYY-MM-DD, ventas DD/MM/YYYY.
        $fecha = null;
        $fe = (string) ($r['fecEmision'] ?? '');
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $fe, $m)) {
            $fecha = "$m[1]-$m[2]-$m[3]";
        } elseif (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $fe, $m)) {
            $fecha = "$m[3]-$m[2]-$m[1]";
        }

        $mt = $r['montos'] ?? null;

        // Ventas: campos planos. OJO con los descuentos — las notas de crédito
        // traen mtoBIGravada = 0 y el importe real (negativo) en mtoDsctoBI.
        $base = isset($r['mtoBIGravada'])
            ? (float) $r['mtoBIGravada'] + (float) ($r['mtoDsctoBI'] ?? 0)
            : ($mt ? (float) ($mt['mtoBIGravadaDG'] ?? 0) + (float) ($mt['mtoBIGravadaDGNG'] ?? 0) + (float) ($mt['mtoBIGravadaDNG'] ?? 0) : 0);

        $igv = isset($r['mtoIGV'])
            ? (float) $r['mtoIGV'] + (float) ($r['mtoDsctoIGV'] ?? 0)
            : ($mt ? (float) ($mt['mtoIgvIpmDG'] ?? 0) + (float) ($mt['mtoIgvIpmDGNG'] ?? 0) + (float) ($mt['mtoIgvIpmDNG'] ?? 0) : 0);

        // Atención a la mayúscula: mtoTotalCP (ventas) vs mtoTotalCp (compras).
        $total = (float) ($r['mtoTotalCP'] ?? ($mt['mtoTotalCp'] ?? 0));

        return [
            'tipo'               => $tipo,
            'cod_tipo_cdp'       => $r['codTipoCDP'] ?? null,
            'serie'              => $r['numSerieCDP'] ?? null,
            'numero'             => $r['numCDP'] ?? null,
            'fecha_emision'      => $fecha,
            'ruc_contraparte'    => $r['numDocIdentidad'] ?? ($r['numDocIdentidadProveedor'] ?? null),
            'nombre_contraparte' => $r['nomRazonSocialCliente']
                                    ?? ($r['nomRazonSocialProveedor'] ?? ($r['nomRazonSocial'] ?? null)),
            'base_gravada'       => round($base, 2),
            'igv'                => round($igv, 2),
            'total'              => round($total, 2),
            'moneda'             => $r['codMoneda'] ?? null,
            'estado_sunat'       => $r['desEstadoComprobante'] ?? null,
            'payload'            => $r,
        ];
    }
}
