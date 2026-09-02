<?php
/**
 * Cotizaciones. Una por empresa, con su propio correlativo.
 *
 * Cada línea puede apuntar a un producto del catálogo o ser texto libre. Eso no
 * es una concesión: de las empresas del grupo sólo una lleva inventario, y las
 * demás cotizan escribiendo la descripción como hacían en su Excel. Cuando la
 * línea sí viene del catálogo, la cotización aceptada puede convertirse en una
 * salida y mover stock.
 */
class Cotizacion
{
    public const IGV = 0.18;

    public const ESTADOS = [
        'BORRADOR'  => 'Borrador',
        'ENVIADA'   => 'Enviada',
        'ACEPTADA'  => 'Aceptada',
        'RECHAZADA' => 'Rechazada',
        'ANULADA'   => 'Anulada',
    ];

    /** Mientras está en borrador se puede tocar todo; después ya salió al cliente. */
    public static function editable(array $cot): bool
    {
        return $cot['estado'] === 'BORRADOR';
    }

    // ------------------------------------------------------------------
    // Consulta
    // ------------------------------------------------------------------

    public static function listar(array $f = [], int $limite = 200): array
    {
        $where = [Empresa::filtro('c')];
        $p = Empresa::param();

        if (!empty($f['estado']))     { $where[] = 'c.estado = :es';      $p[':es'] = $f['estado']; }
        if (!empty($f['cliente_id'])) { $where[] = 'c.cliente_id = :cl';  $p[':cl'] = (int) $f['cliente_id']; }
        if (!empty($f['desde']))      { $where[] = 'c.fecha >= :d';       $p[':d']  = $f['desde']; }
        if (!empty($f['hasta']))      { $where[] = 'c.fecha <= :h';       $p[':h']  = $f['hasta']; }
        if (!empty($f['q'])) {
            $where[] = '(c.cliente_nombre LIKE :q1 OR c.cliente_ruc LIKE :q2 OR c.referencia LIKE :q3)';
            $p[':q1'] = $p[':q2'] = $p[':q3'] = '%' . $f['q'] . '%';
        }

        return DB::todos(
            'SELECT c.*, u.nombres AS usuario,
                    (SELECT COUNT(*) FROM cotizacion_detalle d WHERE d.cotizacion_id = c.id) AS lineas
               FROM cotizaciones c
               LEFT JOIN usuarios u ON u.id = c.usuario_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY c.numero DESC
              LIMIT ' . (int) $limite, $p);
    }

    public static function buscar(int $id): ?array
    {
        $c = DB::uno(
            'SELECT * FROM cotizaciones WHERE id = :id AND ' . Empresa::filtro(),
            Empresa::param() + [':id' => $id]);
        if (!$c) {
            return null;
        }
        $c['detalle'] = DB::todos(
            'SELECT d.*, pr.codigo AS producto_codigo
               FROM cotizacion_detalle d
               LEFT JOIN productos pr ON pr.id = d.producto_id
              WHERE d.cotizacion_id = :c ORDER BY d.linea', [':c' => $id]);
        return $c;
    }

    // ------------------------------------------------------------------
    // Alta y edición
    // ------------------------------------------------------------------

    /**
     * Guarda una cotización con sus líneas. Si $id viene, la reemplaza.
     *
     * @param array $cab   cliente_id o los datos sueltos, fecha, validez, referencia…
     * @param array $lineas cada una: producto_id|null, descripcion, unidad, cantidad, precio_unitario
     */
    public static function guardar(array $cab, array $lineas, ?int $id = null): int
    {
        $lineas = array_values(array_filter($lineas, static function ($l) {
            return trim((string) ($l['descripcion'] ?? '')) !== ''
                && (float) ($l['cantidad'] ?? 0) > 0;
        }));
        if (!$lineas) {
            throw new InvalidArgumentException('La cotización debe tener al menos una línea con descripción y cantidad.');
        }

        return DB::transaccion(static function () use ($cab, $lineas, $id) {
            $empresaId = Empresa::id();
            $cliente   = self::resolverCliente($cab);
            $incluyeIgv = !isset($cab['incluye_igv']) || (bool) $cab['incluye_igv'];

            $datos = [
                'cliente_id'        => $cliente['id'],
                'cliente_nombre'    => $cliente['razon_social'],
                'cliente_ruc'       => $cliente['ruc'],
                'cliente_direccion' => $cliente['direccion'],
                'cliente_email'     => $cliente['email'],
                'fecha'             => ($cab['fecha'] ?? '') ?: date('Y-m-d'),
                'valida_hasta'      => ($cab['valida_hasta'] ?? '') ?: null,
                'referencia'        => trim((string) ($cab['referencia'] ?? '')) ?: null,
                'incluye_igv'       => $incluyeIgv ? 1 : 0,
                'observacion'       => trim((string) ($cab['observacion'] ?? '')) ?: null,
            ];

            // El número normalmente es correlativo automático, pero al pasarse
            // de Excel varias empresas ya venían con su propio número: se
            // acepta forzarlo (sólo mientras la cotización sigue en borrador).
            $numeroForzado = isset($cab['numero']) && trim((string) $cab['numero']) !== ''
                ? (int) $cab['numero'] : null;

            if ($id !== null) {
                $actual = DB::uno('SELECT * FROM cotizaciones WHERE id = :id AND empresa_id = :e',
                    [':id' => $id, ':e' => $empresaId]);
                if (!$actual) {
                    throw new RuntimeException('Esa cotización no es de la empresa activa.');
                }
                if (!self::editable($actual)) {
                    throw new RuntimeException('Sólo se puede modificar una cotización en borrador. '
                        . 'Ésta está ' . mb_strtolower(self::ESTADOS[$actual['estado']]) . '.');
                }
                if ($numeroForzado !== null && $numeroForzado !== (int) $actual['numero']) {
                    self::validarNumeroLibre($numeroForzado, $empresaId, $id);
                    $datos['numero'] = $numeroForzado;
                }
                DB::actualizar('cotizaciones', $datos, 'id = :id', [':id' => $id]);
                DB::eliminar('cotizacion_detalle', 'cotizacion_id = :c', [':c' => $id]);
                $cotId = $id;
            } else {
                if ($numeroForzado !== null) {
                    self::validarNumeroLibre($numeroForzado, $empresaId, null);
                }
                $datos['empresa_id'] = $empresaId;
                $datos['numero']     = $numeroForzado ?? self::siguienteNumero();
                $datos['estado']     = 'BORRADOR';
                $datos['usuario_id'] = Auth::id();
                $cotId = DB::insertar('cotizaciones', $datos);
            }

            $suma = 0.0;
            foreach ($lineas as $i => $l) {
                $cantidad = round((float) $l['cantidad'], 4);
                $precio   = round((float) ($l['precio_unitario'] ?? 0), 4);
                if ($precio < 0) {
                    throw new InvalidArgumentException('El precio no puede ser negativo.');
                }
                $importe = round($cantidad * $precio, 2);
                $suma   += $importe;

                DB::insertar('cotizacion_detalle', [
                    'cotizacion_id'   => $cotId,
                    'linea'           => $i + 1,
                    'producto_id'     => self::productoValido($l['producto_id'] ?? null),
                    'descripcion'     => mb_substr(trim((string) $l['descripcion']), 0, 400),
                    'unidad'          => trim((string) ($l['unidad'] ?? '')) ?: null,
                    'cantidad'        => $cantidad,
                    'precio_unitario' => $precio,
                    'importe'         => $importe,
                ]);
            }

            DB::actualizar('cotizaciones', self::totales($suma, $incluyeIgv),
                'id = :id', [':id' => $cotId]);

            Auditoria::registrar($id ? 'EDITAR' : 'CREAR', 'cotizaciones', $cotId,
                ['lineas' => count($lineas), 'total' => $suma]);
            return $cotId;
        });
    }

    /**
     * Reparto entre base e IGV.
     *
     * Con el precio ya con IGV —que es como cotizan las ocho empresas— la base
     * se saca hacia atrás dividiendo el total, no sumando el impuesto encima.
     * Hacerlo al revés daría un total distinto del que se le enseñó al cliente.
     */
    private static function totales(float $suma, bool $incluyeIgv): array
    {
        if ($incluyeIgv) {
            $total    = round($suma, 2);
            $subtotal = round($total / (1 + self::IGV), 2);
            $igv      = round($total - $subtotal, 2);
        } else {
            $subtotal = round($suma, 2);
            $igv      = round($subtotal * self::IGV, 2);
            $total    = round($subtotal + $igv, 2);
        }
        return ['subtotal' => $subtotal, 'igv' => $igv, 'total' => $total];
    }

    /**
     * Siguiente número de la empresa.
     *
     * Se bloquea la lectura dentro de la transacción, igual que hacen entradas y
     * salidas. Aun así la clave única sobre (empresa, número) es la garantía
     * final: si dos guardan a la vez, la base rechaza el segundo antes de
     * permitir dos cotizaciones con el mismo número.
     */
    public static function siguienteNumero(): int
    {
        return 1 + (int) DB::valor(
            'SELECT COALESCE(MAX(numero), 0) FROM cotizaciones
              WHERE ' . Empresa::filtro() . ' FOR UPDATE', Empresa::param());
    }

    /** El número forzado a mano no puede chocar con uno ya usado por la empresa. */
    private static function validarNumeroLibre(int $numero, int $empresaId, ?int $exceptoId): void
    {
        if ($numero <= 0) {
            throw new InvalidArgumentException('El número de cotización debe ser mayor a 0.');
        }
        $where = 'empresa_id = :e AND numero = :n';
        $p = [':e' => $empresaId, ':n' => $numero];
        if ($exceptoId !== null) {
            $where .= ' AND id != :id';
            $p[':id'] = $exceptoId;
        }
        if (DB::valor("SELECT COUNT(*) FROM cotizaciones WHERE $where", $p)) {
            throw new InvalidArgumentException("Ya existe la cotización N° $numero en esta empresa.");
        }
    }

    /** Un producto de otra empresa no puede colarse en la cotización. */
    private static function productoValido($productoId): ?int
    {
        $id = (int) $productoId;
        if ($id <= 0) {
            return null;                      // línea escrita a mano
        }
        return Producto::buscar($id) ? $id : null;
    }

    /**
     * Datos del cliente. Si viene de la ficha se copian de ahí; si no, se
     * aceptan escritos, que es como se cotiza a alguien que aún no es cliente.
     */
    private static function resolverCliente(array $cab): array
    {
        $id = (int) ($cab['cliente_id'] ?? 0);
        if ($id > 0) {
            $c = DB::uno('SELECT * FROM clientes WHERE id = :id AND ' . Empresa::filtro(),
                Empresa::param() + [':id' => $id]);
            if (!$c) {
                throw new RuntimeException('El cliente no pertenece a la empresa activa.');
            }
            return ['id' => (int) $c['id'], 'razon_social' => $c['razon_social'],
                    'ruc' => $c['ruc'], 'direccion' => $c['direccion'], 'email' => $c['email']];
        }

        $nombre = trim((string) ($cab['cliente_nombre'] ?? ''));
        if ($nombre === '') {
            throw new InvalidArgumentException('Falta el cliente de la cotización.');
        }
        return ['id' => null, 'razon_social' => mb_substr($nombre, 0, 180),
                'ruc'       => trim((string) ($cab['cliente_ruc'] ?? '')) ?: null,
                'direccion' => trim((string) ($cab['cliente_direccion'] ?? '')) ?: null,
                'email'     => trim((string) ($cab['cliente_email'] ?? '')) ?: null];
    }

    // ------------------------------------------------------------------
    // Estado
    // ------------------------------------------------------------------

    public static function cambiarEstado(int $id, string $estado): void
    {
        if (!isset(self::ESTADOS[$estado])) {
            throw new InvalidArgumentException('Estado no válido.');
        }
        $c = self::buscar($id);
        if (!$c) {
            throw new RuntimeException('Esa cotización no es de la empresa activa.');
        }
        if ($c['salida_id'] && $estado !== 'ACEPTADA') {
            throw new RuntimeException('Esta cotización ya generó una salida de inventario: '
                . 'no puede dejar de estar aceptada.');
        }
        DB::actualizar('cotizaciones', ['estado' => $estado], 'id = :id', [':id' => $id]);
        Auditoria::registrar('ESTADO_COTIZACION', 'cotizaciones', $id, ['estado' => $estado]);
    }

    /** Sólo se borra lo que nunca salió al cliente. */
    public static function eliminar(int $id): void
    {
        $c = self::buscar($id);
        if (!$c) {
            throw new RuntimeException('Esa cotización no es de la empresa activa.');
        }
        if (!self::editable($c)) {
            throw new RuntimeException('Sólo se puede eliminar una cotización en borrador. '
                . 'Las demás se anulan, para que no se pierda el rastro de lo que se envió.');
        }
        DB::eliminar('cotizaciones', 'id = :id AND ' . Empresa::filtro(),
            Empresa::param() + [':id' => $id]);
        Auditoria::registrar('ELIMINAR', 'cotizaciones', $id, ['numero' => $c['numero']]);
    }

    /** Copia una cotización a un borrador nuevo: cotizar lo mismo a otro cliente es habitual. */
    public static function duplicar(int $id): int
    {
        $c = self::buscar($id);
        if (!$c) {
            throw new RuntimeException('Esa cotización no es de la empresa activa.');
        }
        return self::guardar([
            'cliente_id'    => $c['cliente_id'],
            'cliente_nombre'=> $c['cliente_nombre'],
            'cliente_ruc'   => $c['cliente_ruc'],
            'cliente_direccion' => $c['cliente_direccion'],
            'cliente_email' => $c['cliente_email'],
            'fecha'         => date('Y-m-d'),
            'valida_hasta'  => null,
            'referencia'    => $c['referencia'],
            'incluye_igv'   => (bool) $c['incluye_igv'],
            'observacion'   => $c['observacion'],
        ], $c['detalle']);
    }
}
