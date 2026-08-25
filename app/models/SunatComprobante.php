<?php
/**
 * Comprobantes traídos del SIRE, guardados por empresa y período.
 */
class SunatComprobante
{
    /**
     * Inserta o actualiza un lote. La clave natural es
     * (empresa, período, tipo, serie, número): volver a sincronizar el mismo
     * período actualiza en vez de duplicar.
     *
     * @return int filas afectadas
     */
    public static function guardarLote(string $periodo, string $tipo, array $filas): int
    {
        if (!$filas) {
            return 0;
        }
        $empresaId = Empresa::id();

        return DB::transaccion(function () use ($filas, $periodo, $tipo, $empresaId) {
            $n = 0;
            foreach ($filas as $f) {
                $datos = [
                    'empresa_id'         => $empresaId,
                    'periodo'            => $periodo,
                    'tipo'               => $tipo,
                    'cod_tipo_cdp'       => $f['cod_tipo_cdp'],
                    'serie'              => $f['serie'],
                    'numero'             => (string) $f['numero'],
                    'fecha_emision'      => $f['fecha_emision'],
                    'ruc_contraparte'    => $f['ruc_contraparte'],
                    'nombre_contraparte' => $f['nombre_contraparte'],
                    'base_gravada'       => $f['base_gravada'],
                    'igv'                => $f['igv'],
                    'total'              => $f['total'],
                    'moneda'             => $f['moneda'],
                    'estado_sunat'       => $f['estado_sunat'],
                    'payload'            => json_encode($f['payload'], JSON_UNESCAPED_UNICODE),
                    'sincronizado_en'    => date('Y-m-d H:i:s'),
                ];

                $cols = array_keys($datos);
                $ph   = array_map(fn($c) => ':' . $c, $cols);
                // Se actualiza todo menos la clave natural.
                $sets = [];
                foreach ($cols as $c) {
                    if (in_array($c, ['empresa_id', 'periodo', 'tipo', 'serie', 'numero'], true)) continue;
                    $sets[] = "$c = VALUES($c)";
                }

                DB::query(
                    'INSERT INTO sunat_comprobantes (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ') '
                    . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $sets),
                    array_combine($ph, array_values($datos)));
                $n++;
            }
            return $n;
        });
    }

    /** Cuántos comprobantes hay realmente guardados de un período y tipo. */
    public static function contar(string $periodo, string $tipo): int
    {
        return (int) DB::valor(
            'SELECT COUNT(*) FROM sunat_comprobantes
              WHERE ' . Empresa::filtro() . ' AND periodo = :per AND tipo = :t',
            Empresa::param() + [':per' => $periodo, ':t' => $tipo]);
    }

    /** Listado con filtros para la pantalla. */
    public static function listar(array $f = [], int $limite = 500): array
    {
        $where = [Empresa::filtro('sc')];
        $p = Empresa::param();

        if (!empty($f['periodo'])) { $where[] = 'sc.periodo = :per'; $p[':per'] = preg_replace('/\D/', '', $f['periodo']); }
        if (!empty($f['tipo']))    { $where[] = 'sc.tipo = :t';      $p[':t']   = $f['tipo']; }
        if (!empty($f['cod']))     { $where[] = 'sc.cod_tipo_cdp = :c'; $p[':c'] = $f['cod']; }
        if (!empty($f['q'])) {
            $where[] = '(sc.serie LIKE :q1 OR sc.numero LIKE :q2 OR sc.nombre_contraparte LIKE :q3 OR sc.ruc_contraparte LIKE :q4)';
            $t = '%' . $f['q'] . '%';
            $p[':q1'] = $t; $p[':q2'] = $t; $p[':q3'] = $t; $p[':q4'] = $t;
        }

        return DB::todos(
            'SELECT sc.* FROM sunat_comprobantes sc
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY sc.fecha_emision DESC, sc.serie, CAST(sc.numero AS UNSIGNED) DESC
              LIMIT ' . (int) $limite, $p);
    }

    /** Totales de lo guardado, para contrastar con SUNAT. */
    public static function totales(array $f = []): array
    {
        $where = [Empresa::filtro('sc')];
        $p = Empresa::param();
        if (!empty($f['periodo'])) { $where[] = 'sc.periodo = :per'; $p[':per'] = preg_replace('/\D/', '', $f['periodo']); }
        if (!empty($f['tipo']))    { $where[] = 'sc.tipo = :t';      $p[':t']   = $f['tipo']; }

        $r = DB::uno(
            'SELECT COUNT(*) AS documentos,
                    COALESCE(SUM(base_gravada),0) AS base,
                    COALESCE(SUM(igv),0)          AS igv,
                    COALESCE(SUM(total),0)        AS total
               FROM sunat_comprobantes sc WHERE ' . implode(' AND ', $where), $p);

        return [
            'documentos' => (int) $r['documentos'],
            'base'  => (float) $r['base'],
            'igv'   => (float) $r['igv'],
            'total' => (float) $r['total'],
        ];
    }

    /** Períodos ya sincronizados, con cuántos comprobantes tiene cada uno. */
    public static function periodosSincronizados(): array
    {
        $filas = DB::todos(
            'SELECT periodo,
                    SUM(tipo = \'ventas\')  AS ventas,
                    SUM(tipo = \'compras\') AS compras,
                    MAX(sincronizado_en)    AS ultima
               FROM sunat_comprobantes
              WHERE ' . Empresa::filtro() . '
              GROUP BY periodo ORDER BY periodo DESC', Empresa::param());

        $out = [];
        foreach ($filas as $f) {
            $out[$f['periodo']] = $f;
        }
        return $out;
    }

    /** Etiqueta legible del tipo de documento. */
    public static function tipoDoc(?string $cod): string
    {
        return [
            '01' => 'Factura', '03' => 'Boleta', '07' => 'Nota de crédito',
            '08' => 'Nota de débito', '12' => 'Ticket', '14' => 'Recibo servicios',
        ][$cod] ?? ('Tipo ' . ($cod ?? '?'));
    }
}
