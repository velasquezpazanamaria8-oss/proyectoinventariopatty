<?php
/**
 * Pasada automática de sincronización con SUNAT.
 *
 * Hace, en este orden y con presupuesto de tiempo:
 *   1. Trae del SIRE los períodos abiertos (el actual y el anterior)
 *   2. Descarga los comprobantes pendientes
 *
 * NO genera movimientos de inventario a propósito: eso mueve stock y exige
 * que alguien haya conciliado los productos y revisado el saldo inicial.
 * Automatizarlo sería mover existencias sin que nadie lo haya mirado.
 *
 * Un solo trabajo por empresa a la vez: SUNAT rechaza dos sesiones
 * simultáneas del mismo RUC, y dos pasadas competirían por el mismo token.
 */
class SunatTarea
{
    /** Minutos tras los cuales una tarea "corriendo" se da por colgada. */
    private const MINUTOS_COLGADA = 30;

    /**
     * Ejecuta una pasada para la empresa activa.
     *
     * @param int $segundos presupuesto; el cron del hosting corta a los pocos minutos
     */
    public static function ejecutar(int $segundos = 240, string $origen = 'cron'): array
    {
        self::liberarColgadas();

        if (self::hayOtraCorriendo()) {
            return ['ok' => false, 'mensaje' => 'Ya hay una sincronización en curso para esta empresa.'];
        }
        if (!CredencialSunat::existe()) {
            return ['ok' => false, 'mensaje' => 'La empresa no tiene credenciales SUNAT configuradas.'];
        }

        $tareaId = DB::insertar('sunat_tareas', Empresa::sello([
            'origen' => $origen, 'estado' => 'CORRIENDO',
        ]));

        $inicio = microtime(true);
        $r = ['sincronizados' => [], 'descargados' => 0, 'errores' => 0];

        try {
            $cred = CredencialSunat::descifradas();

            // --- 1. SIRE de los períodos vivos ---
            // El mes en curso y el anterior: los comprobantes siguen llegando
            // durante días después de cerrar el mes.
            foreach (self::periodosVivos() as $per) {
                if (microtime(true) - $inicio > $segundos * 0.5) {
                    break;                       // deja tiempo para descargar
                }
                try {
                    $s = SunatSire::sincronizar($cred, $per);
                    $r['sincronizados'][$per] = array_sum(array_column($s['tipos'], 'guardados'));
                } catch (Throwable $e) {
                    $r['errores']++;
                    $r['sire_error'] = mb_substr($e->getMessage(), 0, 200);
                }
            }

            // --- 2. Descarga de lo pendiente, del período más reciente atrás ---
            foreach (array_keys(SunatComprobante::periodosSincronizados()) as $per) {
                while (microtime(true) - $inicio < $segundos) {
                    $pend = SunatCpeItem::pendientes($per, 1);
                    if (!$pend) break;

                    $c = $pend[0];
                    try {
                        $d = SunatCpe::descargar($cred, $c);
                        if ($d['error'] !== null) {
                            SunatCpeItem::anotarError((int) $c['id'], $d['error']);
                            $r['errores']++;
                        } else {
                            $r['descargados']++;
                        }
                    } catch (Throwable $e) {
                        SunatCpeItem::anotarError((int) $c['id'], $e->getMessage());
                        $r['errores']++;
                    }
                }
                if (microtime(true) - $inicio >= $segundos) break;
            }

            $resumen = sprintf('SIRE: %s · descargados: %d · errores: %d',
                $r['sincronizados'] ? implode(', ', array_map(
                    fn($p, $n) => "$p=$n", array_keys($r['sincronizados']), $r['sincronizados'])) : 'sin cambios',
                $r['descargados'], $r['errores']);

            self::cerrar($tareaId, 'OK', $resumen, $r);
            return ['ok' => true, 'mensaje' => $resumen, 'detalle' => $r];

        } catch (Throwable $e) {
            self::cerrar($tareaId, 'ERROR', mb_substr($e->getMessage(), 0, 400), $r);
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        }
    }

    /** Período actual y anterior, en formato YYYYMM. */
    private static function periodosVivos(): array
    {
        return [date('Ym'), date('Ym', strtotime('first day of last month'))];
    }

    private static function hayOtraCorriendo(): bool
    {
        return (int) DB::valor(
            'SELECT COUNT(*) FROM sunat_tareas
              WHERE ' . Empresa::filtro() . ' AND estado = \'CORRIENDO\'',
            Empresa::param()) > 0;
    }

    /**
     * Una tarea que quedó "corriendo" porque el proceso murió bloquearía la
     * empresa para siempre. Pasado un tiempo prudente se marca como error.
     */
    private static function liberarColgadas(): void
    {
        DB::query(
            'UPDATE sunat_tareas
                SET estado = \'ERROR\', terminado_en = NOW(),
                    resumen = CONCAT(COALESCE(resumen,\'\'), \' [proceso interrumpido]\')
              WHERE ' . Empresa::filtro() . ' AND estado = \'CORRIENDO\'
                AND iniciado_en < DATE_SUB(NOW(), INTERVAL ' . self::MINUTOS_COLGADA . ' MINUTE)',
            Empresa::param());
    }

    private static function cerrar(int $id, string $estado, string $resumen, array $detalle): void
    {
        DB::actualizar('sunat_tareas', [
            'estado'       => $estado,
            'resumen'      => mb_substr($resumen, 0, 500),
            'detalle'      => json_encode($detalle, JSON_UNESCAPED_UNICODE),
            'terminado_en' => date('Y-m-d H:i:s'),
        ], 'id = :id', [':id' => $id]);
    }

    /** Últimas ejecuciones, para la pantalla de estado. */
    public static function historial(int $limite = 20): array
    {
        return DB::todos(
            'SELECT * FROM sunat_tareas WHERE ' . Empresa::filtro() . '
              ORDER BY id DESC LIMIT ' . (int) $limite, Empresa::param());
    }

    /** Empresas con credenciales, para que el cron las recorra todas. */
    public static function empresasConCredenciales(): array
    {
        return DB::todos(
            'SELECT e.id, e.nombre_corto FROM credenciales_sunat c
               JOIN empresas e ON e.id = c.empresa_id
              WHERE e.estado = 1 ORDER BY e.id');
    }
}
