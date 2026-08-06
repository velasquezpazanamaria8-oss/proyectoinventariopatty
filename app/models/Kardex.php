<?php
/**
 * Motor del Kardex. RF-05, RF-06, RF-07, RF-08.
 *
 * Reglas aplicadas:
 *  - Todo movimiento genera un registro inmutable en `kardex` (RB-02, RB-04).
 *  - El stock se actualiza automáticamente en la misma transacción (RB-03).
 *  - No se permiten salidas sin stock suficiente (RB-01).
 *  - Valorización por costo promedio ponderado.
 *  - Multiempresa: el producto y el almacén deben pertenecer a la empresa activa.
 */
class Kardex
{
    public const ENTRADA     = 'ENTRADA';
    public const SALIDA      = 'SALIDA';
    public const AJUSTE_POS  = 'AJUSTE_POS';
    public const AJUSTE_NEG  = 'AJUSTE_NEG';
    public const INV_INICIAL = 'INV_INICIAL';

    /**
     * Registra un movimiento y actualiza stock + costo promedio.
     * DEBE invocarse dentro de una transacción abierta por el llamador.
     */
    public static function registrar(array $m): int
    {
        $empresaId  = Empresa::id();
        $productoId = (int) $m['producto_id'];
        $almacenId  = (int) $m['almacen_id'];
        $tipo       = $m['tipo'];
        $cantidad   = round((float) $m['cantidad'], 4);
        $costoUnit  = round((float) ($m['costo_unitario'] ?? 0), 4);

        if ($cantidad <= 0) {
            throw new InvalidArgumentException('La cantidad del movimiento debe ser mayor a cero.');
        }

        // Barrera de aislamiento: ni el producto ni el almacén pueden ser de otra empresa.
        $producto = DB::uno(
            'SELECT id, codigo, descripcion, costo_promedio FROM productos
              WHERE id = :p AND empresa_id = :e',
            [':p' => $productoId, ':e' => $empresaId]);
        if (!$producto) {
            throw new RuntimeException('El producto no pertenece a la empresa activa.');
        }
        $almacenValido = (int) DB::valor(
            'SELECT COUNT(*) FROM almacenes WHERE id = :a AND empresa_id = :e',
            [':a' => $almacenId, ':e' => $empresaId]);
        if (!$almacenValido) {
            throw new RuntimeException('El almacén no pertenece a la empresa activa.');
        }

        // Bloqueo de la fila de stock para evitar condiciones de carrera.
        $stock = DB::uno(
            'SELECT cantidad FROM stock WHERE producto_id = :p AND almacen_id = :a FOR UPDATE',
            [':p' => $productoId, ':a' => $almacenId]);
        if ($stock === null) {
            DB::insertar('stock', ['producto_id' => $productoId, 'almacen_id' => $almacenId, 'cantidad' => 0, 'reservado' => 0]);
            $saldoAnterior = 0.0;
        } else {
            $saldoAnterior = (float) $stock['cantidad'];
        }

        $esIngreso = in_array($tipo, [self::ENTRADA, self::AJUSTE_POS, self::INV_INICIAL], true);

        if ($esIngreso) {
            $saldoNuevo = round($saldoAnterior + $cantidad, 4);
        } else {
            // Regla de negocio RB-01: no se sale más de lo que hay.
            if (round($cantidad - $saldoAnterior, 4) > 0) {
                throw new RuntimeException(sprintf(
                    'Stock insuficiente para "%s (%s)". Disponible: %s, solicitado: %s.',
                    $producto['descripcion'], $producto['codigo'], $saldoAnterior, $cantidad
                ));
            }
            $saldoNuevo = round($saldoAnterior - $cantidad, 4);
        }

        // El stock se actualiza ANTES de valorizar: el promedio de ámbito
        // global necesita el saldo ya sumado para ponderar correctamente.
        DB::actualizar('stock', ['cantidad' => $saldoNuevo],
            'producto_id = :p AND almacen_id = :a', [':p' => $productoId, ':a' => $almacenId]);

        // El método de valorización (promedio global, promedio por almacén,
        // PEPS o UEPS) decide a qué costo se registra el movimiento.
        $v = $esIngreso
            ? Valorizacion::ingreso($productoId, $almacenId, $cantidad, $costoUnit, [
                'fecha'     => $m['fecha'] ?? date('Y-m-d H:i:s'),
                'documento' => $m['documento'] ?? null,
              ])
            : Valorizacion::salida($productoId, $almacenId, $cantidad);

        $costoUnit  = $v['costo_unitario'];
        $costoNuevo = $v['costo_saldo'];

        $kardexId = DB::insertar('kardex', [
            'empresa_id'     => $empresaId,
            'producto_id'    => $productoId,
            'almacen_id'     => $almacenId,
            'fecha'          => $m['fecha'] ?? date('Y-m-d H:i:s'),
            'tipo'           => $tipo,
            'origen_tabla'   => $m['origen_tabla'],
            'origen_id'      => (int) $m['origen_id'],
            'documento'      => $m['documento'] ?? null,
            'cantidad'       => $cantidad,
            'costo_unitario' => $costoUnit,
            'saldo_cantidad' => $saldoNuevo,
            'saldo_costo'    => $costoNuevo,
            // Con capas el valor pendiente es exacto; con promedio se deriva
            // del costo unitario vigente.
            'saldo_valor'    => Valorizacion::usaCapas()
                ? Valorizacion::saldoDeCapas($productoId, $almacenId)['valor']
                : round($saldoNuevo * $costoNuevo, 4),
            'motivo'         => $m['motivo'] ?? null,
            'usuario_id'     => Auth::id(),
        ]);

        // Trazabilidad de PEPS/UEPS: qué capa nació y cuáles se consumieron.
        Valorizacion::enlazarCapa($v['capa_id'] ?? null, $kardexId);
        Valorizacion::registrarConsumos($kardexId, $v['consumos']);

        // Costo de las existencias de este almacén tras el movimiento.
        DB::actualizar('stock', ['costo_promedio' => $costoNuevo],
            'producto_id = :p AND almacen_id = :a', [':p' => $productoId, ':a' => $almacenId]);

        if (Valorizacion::ambito() === Valorizacion::AMBITO_GLOBAL) {
            // Un solo costo para todo el producto: se replica a cada almacén
            // para que los reportes por almacén cuadren con el global.
            DB::query('UPDATE stock SET costo_promedio = :c WHERE producto_id = :p',
                [':c' => $costoNuevo, ':p' => $productoId]);
            DB::actualizar('productos', ['costo_promedio' => $costoNuevo],
                'id = :p AND empresa_id = :e', [':p' => $productoId, ':e' => $empresaId]);
        } else {
            // Cada almacén tiene su costo: el del producto es el ponderado.
            Valorizacion::recalcularCostoGlobal($productoId);
        }

        return $kardexId;
    }

    /** Historial de un producto. RF-08. */
    public static function porProducto(int $productoId, ?int $almacenId = null, ?string $desde = null, ?string $hasta = null): array
    {
        $where = [Empresa::filtro('k'), 'k.producto_id = :p'];
        $p = Empresa::param() + [':p' => $productoId];
        if ($almacenId) { $where[] = 'k.almacen_id = :a'; $p[':a'] = $almacenId; }
        if ($desde)     { $where[] = 'k.fecha >= :d';     $p[':d'] = $desde . ' 00:00:00'; }
        if ($hasta)     { $where[] = 'k.fecha <= :h';     $p[':h'] = $hasta . ' 23:59:59'; }

        return DB::todos(
            'SELECT k.*, a.nombre AS almacen, u.usuario
               FROM kardex k
               JOIN almacenes a ON a.id = k.almacen_id
               JOIN usuarios  u ON u.id = k.usuario_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY k.fecha ASC, k.id ASC', $p);
    }

    /** Kardex general de la empresa activa. */
    public static function general(array $f = [], int $limite = 500): array
    {
        $where = [Empresa::filtro('k')];
        $p = Empresa::param();
        if (!empty($f['almacen_id'])) { $where[] = 'k.almacen_id = :a'; $p[':a'] = $f['almacen_id']; }
        if (!empty($f['tipo']))       { $where[] = 'k.tipo = :t';       $p[':t'] = $f['tipo']; }
        if (!empty($f['usuario_id'])) { $where[] = 'k.usuario_id = :u'; $p[':u'] = $f['usuario_id']; }
        if (!empty($f['desde']))      { $where[] = 'k.fecha >= :d';     $p[':d'] = $f['desde'] . ' 00:00:00'; }
        if (!empty($f['hasta']))      { $where[] = 'k.fecha <= :h';     $p[':h'] = $f['hasta'] . ' 23:59:59'; }

        return DB::todos(
            'SELECT k.*, pr.codigo, pr.descripcion, a.nombre AS almacen, u.usuario
               FROM kardex k
               JOIN productos  pr ON pr.id = k.producto_id
               JOIN almacenes  a  ON a.id  = k.almacen_id
               JOIN usuarios   u  ON u.id  = k.usuario_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY k.id DESC LIMIT ' . (int) $limite, $p);
    }
}
