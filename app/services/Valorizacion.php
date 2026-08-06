<?php
/**
 * Motor de valorización del kardex.
 *
 * Resuelve la única pregunta que distingue a un método de otro:
 * ¿a qué costo sale una unidad del almacén?
 *
 *   PROMEDIO (ámbito GLOBAL)  un costo por producto, sumando todos los almacenes
 *   PROMEDIO (ámbito ALMACEN) un costo distinto en cada almacén
 *   PEPS                      sale primero lo que entró primero (FIFO)
 *   UEPS                      sale primero lo que entró último (LIFO)
 *
 * PEPS y UEPS trabajan con capas de costo (`capas_costo`): cada ingreso crea
 * una capa y cada salida las consume en orden. Siempre por almacén, porque una
 * capa es mercadería física que está en un sitio concreto.
 *
 * Todos los métodos devuelven la misma estructura, para que Kardex::registrar
 * no tenga que saber con cuál está trabajando:
 *   costo_unitario  costo con el que se valoriza ESTE movimiento
 *   costo_saldo     costo unitario de lo que queda en el almacén tras él
 *   consumos        capas consumidas (sólo PEPS/UEPS), para la trazabilidad
 */
class Valorizacion
{
    public const PROMEDIO = 'PROMEDIO';
    public const PEPS     = 'PEPS';
    public const UEPS     = 'UEPS';

    public const AMBITO_GLOBAL  = 'GLOBAL';
    public const AMBITO_ALMACEN = 'ALMACEN';

    public const METODOS = [
        self::PROMEDIO => 'Promedio ponderado',
        self::PEPS     => 'PEPS — primeras entradas, primeras salidas',
        self::UEPS     => 'UEPS — últimas entradas, primeras salidas',
    ];

    /** Método configurado en la empresa activa. */
    public static function metodo(): string
    {
        $m = Empresa::actual()['metodo_valorizacion'] ?? self::PROMEDIO;
        return isset(self::METODOS[$m]) ? $m : self::PROMEDIO;
    }

    /** Ámbito del promedio. Con PEPS/UEPS el costo es siempre por almacén. */
    public static function ambito(): string
    {
        if (self::metodo() !== self::PROMEDIO) {
            return self::AMBITO_ALMACEN;
        }
        return (Empresa::actual()['ambito_costo'] ?? self::AMBITO_GLOBAL) === self::AMBITO_ALMACEN
            ? self::AMBITO_ALMACEN : self::AMBITO_GLOBAL;
    }

    public static function etiqueta(): string
    {
        $t = self::METODOS[self::metodo()];
        if (self::metodo() === self::PROMEDIO) {
            $t .= self::ambito() === self::AMBITO_GLOBAL ? ' (costo global)' : ' (costo por almacén)';
        }
        return $t;
    }

    public static function usaCapas(): bool
    {
        return self::metodo() !== self::PROMEDIO;
    }

    // ==================================================================
    // INGRESOS
    // ==================================================================

    /**
     * Valoriza un ingreso y deja el costo listo para el siguiente movimiento.
     * Debe ejecutarse dentro de la transacción del movimiento.
     */
    public static function ingreso(int $productoId, int $almacenId, float $cantidad,
                                   float $costoUnitario, array $ctx): array
    {
        $costoAlmacen = self::costoAlmacen($productoId, $almacenId);

        // Un ingreso sin costo (típico en un ajuste positivo) hereda el costo
        // vigente: de lo contrario valorizaría las existencias en cero.
        if ($costoUnitario <= 0) {
            $costoUnitario = $costoAlmacen > 0 ? $costoAlmacen : self::costoGlobal($productoId);
        }

        if (self::usaCapas()) {
            $capaId = DB::insertar('capas_costo', [
                'empresa_id'     => Empresa::id(),
                'producto_id'    => $productoId,
                'almacen_id'     => $almacenId,
                'kardex_id'      => null,          // se enlaza al crear el kardex
                'fecha'          => $ctx['fecha'],
                'cantidad_ini'   => $cantidad,
                'cantidad_resta' => $cantidad,
                'costo_unitario' => $costoUnitario,
                'documento'      => $ctx['documento'] ?? null,
            ]);
            return [
                'costo_unitario' => $costoUnitario,
                'costo_saldo'    => self::costoDeCapas($productoId, $almacenId),
                'consumos'       => [],
                'capa_id'        => $capaId,
            ];
        }

        // --- Promedio ponderado móvil ---
        if (self::ambito() === self::AMBITO_GLOBAL) {
            $saldoPrevio  = self::cantidadGlobal($productoId) - $cantidad; // ya sumada por el llamador
            $costoPrevio  = self::costoGlobal($productoId);
        } else {
            $saldoPrevio  = self::cantidadAlmacen($productoId, $almacenId) - $cantidad;
            $costoPrevio  = $costoAlmacen;
        }
        $saldoPrevio = max(0, round($saldoPrevio, 4));
        $saldoNuevo  = round($saldoPrevio + $cantidad, 4);

        $costoSaldo = $saldoNuevo > 0
            ? round((($saldoPrevio * $costoPrevio) + ($cantidad * $costoUnitario)) / $saldoNuevo, 4)
            : $costoUnitario;

        return [
            'costo_unitario' => $costoUnitario,
            'costo_saldo'    => $costoSaldo,
            'consumos'       => [],
            'capa_id'        => null,
        ];
    }

    // ==================================================================
    // SALIDAS
    // ==================================================================

    /**
     * Valoriza una salida. La disponibilidad de stock ya fue validada por
     * Kardex::registrar; aquí sólo se decide el costo.
     */
    public static function salida(int $productoId, int $almacenId, float $cantidad): array
    {
        if (!self::usaCapas()) {
            // En promedio, la salida no altera el costo: sale al vigente.
            $costo = self::ambito() === self::AMBITO_GLOBAL
                ? self::costoGlobal($productoId)
                : self::costoAlmacen($productoId, $almacenId);

            return ['costo_unitario' => $costo, 'costo_saldo' => $costo, 'consumos' => []];
        }

        // --- PEPS / UEPS: consumo de capas ---
        $orden = self::metodo() === self::PEPS ? 'ASC' : 'DESC';
        $capas = DB::todos(
            "SELECT id, cantidad_resta, costo_unitario
               FROM capas_costo
              WHERE producto_id = :p AND almacen_id = :a AND cantidad_resta > 0
              ORDER BY fecha $orden, id $orden
              FOR UPDATE",
            [':p' => $productoId, ':a' => $almacenId]);

        $porConsumir = round($cantidad, 4);
        $valorTotal  = 0.0;
        $consumos    = [];

        foreach ($capas as $capa) {
            if ($porConsumir <= 0) break;

            $tomar = min($porConsumir, (float) $capa['cantidad_resta']);
            $tomar = round($tomar, 4);
            if ($tomar <= 0) continue;

            DB::query(
                'UPDATE capas_costo SET cantidad_resta = cantidad_resta - :c WHERE id = :id',
                [':c' => $tomar, ':id' => $capa['id']]);

            $valorTotal += $tomar * (float) $capa['costo_unitario'];
            $consumos[] = [
                'capa_id'        => (int) $capa['id'],
                'cantidad'       => $tomar,
                'costo_unitario' => (float) $capa['costo_unitario'],
            ];
            $porConsumir = round($porConsumir - $tomar, 4);
        }

        // Si las capas no alcanzan (datos migrados de otro método, por ejemplo)
        // el resto se valoriza al último costo conocido en lugar de fallar.
        if ($porConsumir > 0) {
            $respaldo = self::costoAlmacen($productoId, $almacenId);
            if ($respaldo <= 0) {
                $respaldo = self::costoGlobal($productoId);
            }
            $valorTotal += $porConsumir * $respaldo;
            error_log(sprintf(
                'Valorizacion: faltaron capas para producto %d almacén %d (%s unidades al costo de respaldo %s)',
                $productoId, $almacenId, $porConsumir, $respaldo));
        }

        $costoUnitario = $cantidad > 0 ? round($valorTotal / $cantidad, 4) : 0.0;

        return [
            'costo_unitario' => $costoUnitario,
            // Valor exacto de las capas consumidas. El costo unitario va
            // redondeado a 4 decimales, así que multiplicarlo por la cantidad
            // puede desviarse un céntimo: para el importe se usa esta suma.
            'valor_total'    => round($valorTotal, 4),
            'costo_saldo'    => self::costoDeCapas($productoId, $almacenId),
            'consumos'       => $consumos,
        ];
    }

    /**
     * Importe exacto de un movimiento. Con capas es la suma de lo consumido;
     * con promedio, cantidad por costo.
     */
    public static function valorMovimiento(int $kardexId, float $cantidad, float $costoUnitario): float
    {
        $suma = DB::valor(
            'SELECT SUM(cantidad * costo_unitario) FROM kardex_capa WHERE kardex_id = :k',
            [':k' => $kardexId]);

        return $suma !== null ? round((float) $suma, 4) : round($cantidad * $costoUnitario, 4);
    }

    /** Guarda el detalle de capas consumidas por un movimiento. */
    public static function registrarConsumos(int $kardexId, array $consumos): void
    {
        foreach ($consumos as $c) {
            DB::insertar('kardex_capa', [
                'kardex_id'      => $kardexId,
                'capa_id'        => $c['capa_id'],
                'cantidad'       => $c['cantidad'],
                'costo_unitario' => $c['costo_unitario'],
            ]);
        }
    }

    /** Enlaza la capa recién creada con el movimiento que la originó. */
    public static function enlazarCapa(?int $capaId, int $kardexId): void
    {
        if ($capaId) {
            DB::actualizar('capas_costo', ['kardex_id' => $kardexId], 'id = :id', [':id' => $capaId]);
        }
    }

    /** Capas consumidas por un movimiento, para mostrarlas en el kardex. */
    public static function consumosDe(int $kardexId): array
    {
        return DB::todos(
            'SELECT kc.cantidad, kc.costo_unitario, c.fecha, c.documento
               FROM kardex_capa kc
               JOIN capas_costo c ON c.id = kc.capa_id
              WHERE kc.kardex_id = :k
              ORDER BY kc.id', [':k' => $kardexId]);
    }

    /** Capas con saldo de un producto, para consultarlas en pantalla. */
    public static function capasVigentes(int $productoId, ?int $almacenId = null): array
    {
        $p = [':p' => $productoId];
        $filtro = '';
        if ($almacenId) { $filtro = ' AND c.almacen_id = :a'; $p[':a'] = $almacenId; }

        return DB::todos(
            'SELECT c.*, a.nombre AS almacen
               FROM capas_costo c
               JOIN almacenes a ON a.id = c.almacen_id
              WHERE c.producto_id = :p AND c.cantidad_resta > 0' . $filtro . '
              ORDER BY c.fecha, c.id', $p);
    }

    // ==================================================================
    // Consultas de apoyo
    // ==================================================================

    public static function cantidadAlmacen(int $productoId, int $almacenId): float
    {
        return (float) DB::valor(
            'SELECT COALESCE(cantidad, 0) FROM stock WHERE producto_id = :p AND almacen_id = :a',
            [':p' => $productoId, ':a' => $almacenId]);
    }

    public static function cantidadGlobal(int $productoId): float
    {
        return (float) DB::valor(
            'SELECT COALESCE(SUM(cantidad), 0) FROM stock WHERE producto_id = :p', [':p' => $productoId]);
    }

    public static function costoAlmacen(int $productoId, int $almacenId): float
    {
        return (float) DB::valor(
            'SELECT COALESCE(costo_promedio, 0) FROM stock WHERE producto_id = :p AND almacen_id = :a',
            [':p' => $productoId, ':a' => $almacenId]);
    }

    public static function costoGlobal(int $productoId): float
    {
        return (float) DB::valor(
            'SELECT COALESCE(costo_promedio, 0) FROM productos WHERE id = :p', [':p' => $productoId]);
    }

    /** Costo unitario de lo que queda en capas de un almacén. */
    public static function costoDeCapas(int $productoId, int $almacenId): float
    {
        $r = self::saldoDeCapas($productoId, $almacenId);
        return $r['cantidad'] > 0 ? round($r['valor'] / $r['cantidad'], 4) : 0.0;
    }

    /** Cantidad y valor exactos pendientes en capas. */
    public static function saldoDeCapas(int $productoId, int $almacenId): array
    {
        $r = DB::uno(
            'SELECT COALESCE(SUM(cantidad_resta), 0) AS cant,
                    COALESCE(SUM(cantidad_resta * costo_unitario), 0) AS valor
               FROM capas_costo
              WHERE producto_id = :p AND almacen_id = :a AND cantidad_resta > 0',
            [':p' => $productoId, ':a' => $almacenId]);

        return ['cantidad' => (float) $r['cant'], 'valor' => round((float) $r['valor'], 4)];
    }

    /**
     * Recalcula el costo global del producto a partir de lo que hay en cada
     * almacén. Es el número que usan los reportes valorizados.
     */
    public static function recalcularCostoGlobal(int $productoId): float
    {
        $r = DB::uno(
            'SELECT COALESCE(SUM(cantidad), 0) AS cant,
                    COALESCE(SUM(cantidad * costo_promedio), 0) AS valor
               FROM stock WHERE producto_id = :p', [':p' => $productoId]);

        $cant  = (float) $r['cant'];
        $costo = $cant > 0 ? round((float) $r['valor'] / $cant, 4) : null;

        if ($costo === null) {
            // Sin existencias se conserva el último costo conocido.
            return self::costoGlobal($productoId);
        }
        DB::actualizar('productos', ['costo_promedio' => $costo], 'id = :p', [':p' => $productoId]);
        return $costo;
    }

    /**
     * ¿Se puede cambiar el método? Sólo mientras no haya movimientos: cambiarlo
     * después dejaría un kardex con dos criterios mezclados.
     */
    public static function puedeCambiarMetodo(int $empresaId): bool
    {
        return (int) DB::valor('SELECT COUNT(*) FROM kardex WHERE empresa_id = :e LIMIT 1',
            [':e' => $empresaId]) === 0;
    }
}
