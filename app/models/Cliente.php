<?php
/**
 * Clientes de la empresa.
 *
 * El día a día se lleva con el catálogo genérico (Catalogo::TABLAS['clientes']),
 * que ya da alta, edición, búsqueda y baja. Aquí vive sólo lo que ese catálogo
 * no puede hacer: traerlos desde los comprobantes de venta ya importados.
 */
class Cliente
{
    /**
     * Da de alta a los clientes que aparecen en las ventas descargadas de SUNAT.
     *
     * Es la forma rápida de partir con la lista hecha: si la empresa ya importó
     * su SIRE, sus clientes reales están ahí con RUC y razón social tal como los
     * declaró. Tecleárlos de nuevo sería trabajo repetido y una fuente de erratas.
     *
     * No pisa lo que ya exista: un cliente cuyos datos se corrigieron a mano no
     * debe volver atrás porque SUNAT lo tenga escrito de otra manera.
     *
     * @return array{creados:int, existentes:int, sin_ruc:int}
     */
    public static function sembrarDesdeVentas(): array
    {
        $r = ['creados' => 0, 'existentes' => 0, 'sin_ruc' => 0];

        // Se toma el nombre más reciente de cada RUC: si el cliente cambió de
        // razón social, la última declarada es la buena.
        $filas = DB::todos(
            'SELECT c.ruc_contraparte AS ruc,
                    SUBSTRING_INDEX(GROUP_CONCAT(c.nombre_contraparte
                        ORDER BY c.fecha_emision DESC SEPARATOR \'|#|\'), \'|#|\', 1) AS razon_social,
                    COUNT(*) AS comprobantes
               FROM sunat_comprobantes c
              WHERE ' . Empresa::filtro('c') . ' AND c.tipo = \'ventas\'
                AND c.nombre_contraparte IS NOT NULL AND c.nombre_contraparte <> \'\'
              GROUP BY c.ruc_contraparte
              ORDER BY comprobantes DESC',
            Empresa::param());

        foreach ($filas as $f) {
            $ruc = preg_replace('/\D/', '', (string) $f['ruc']);
            if ($ruc === '') {
                // Boletas a consumidor final: sin documento no hay a quién fichar.
                $r['sin_ruc']++;
                continue;
            }

            $ya = DB::valor(
                'SELECT id FROM clientes WHERE ' . Empresa::filtro() . ' AND ruc = :r',
                Empresa::param() + [':r' => $ruc]);
            if ($ya) {
                $r['existentes']++;
                continue;
            }

            DB::insertar('clientes', Empresa::sello([
                'ruc'          => $ruc,
                'razon_social' => mb_substr(trim((string) $f['razon_social']), 0, 180),
                'estado'       => 1,
            ]));
            $r['creados']++;
        }

        Auditoria::registrar('CLIENTES_SEMBRADOS', 'clientes', null, $r);
        return $r;
    }

    /** Cuántos clientes se podrían traer de las ventas sin repetir los que ya están. */
    public static function porSembrar(): int
    {
        return (int) DB::valor(
            'SELECT COUNT(DISTINCT c.ruc_contraparte)
               FROM sunat_comprobantes c
              WHERE ' . Empresa::filtro('c') . ' AND c.tipo = \'ventas\'
                AND c.ruc_contraparte REGEXP \'^[0-9]+$\'
                AND c.nombre_contraparte IS NOT NULL AND c.nombre_contraparte <> \'\'
                AND NOT EXISTS (SELECT 1 FROM clientes cl
                                 WHERE cl.empresa_id = c.empresa_id AND cl.ruc = c.ruc_contraparte)',
            Empresa::param());
    }

    /** Busca por RUC dentro de la empresa activa. */
    public static function porRuc(string $ruc): ?array
    {
        return DB::uno(
            'SELECT * FROM clientes WHERE ' . Empresa::filtro() . ' AND ruc = :r',
            Empresa::param() + [':r' => preg_replace('/\D/', '', $ruc)]);
    }
}
