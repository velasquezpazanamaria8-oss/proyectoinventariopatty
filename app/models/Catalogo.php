<?php
/**
 * CRUD genérico para los catálogos simples: categorías, marcas,
 * unidades, proveedores y almacenes. RF-04.
 * Todos pertenecen a una empresa.
 */
class Catalogo
{
    public const TABLAS = [
        'categorias'  => ['etiqueta' => 'Categorías',  'campos' => ['nombre', 'descripcion', 'estado'],                       'label' => 'nombre'],
        'marcas'      => ['etiqueta' => 'Marcas',      'campos' => ['nombre', 'estado'],                                      'label' => 'nombre'],
        'unidades'    => ['etiqueta' => 'Unidades',    'campos' => ['codigo', 'nombre', 'decimales'],                         'label' => 'nombre'],
        'proveedores' => ['etiqueta' => 'Proveedores', 'campos' => ['ruc', 'razon_social', 'telefono', 'email', 'direccion', 'estado'], 'label' => 'razon_social'],
        'almacenes'   => ['etiqueta' => 'Almacenes',   'campos' => ['codigo', 'nombre', 'direccion', 'estado'],               'label' => 'nombre'],
    ];

    public static function valida(string $tabla): bool
    {
        return isset(self::TABLAS[$tabla]);
    }

    private static function asegurar(string $tabla): array
    {
        if (!self::valida($tabla)) {
            throw new InvalidArgumentException("Catálogo no permitido: $tabla");
        }
        return self::TABLAS[$tabla];
    }

    public static function listar(string $tabla, string $q = ''): array
    {
        $meta  = self::asegurar($tabla);
        $where = Empresa::filtro();
        $p     = Empresa::param();
        if ($q !== '') {
            $where .= " AND {$meta['label']} LIKE :q";
            $p[':q'] = "%$q%";
        }
        return DB::todos("SELECT * FROM $tabla WHERE $where ORDER BY {$meta['label']}", $p);
    }

    /** Opciones para <select>: [id => etiqueta] */
    public static function opciones(string $tabla, bool $soloActivos = true): array
    {
        $meta  = self::asegurar($tabla);
        $where = Empresa::filtro();
        if ($soloActivos && in_array('estado', $meta['campos'], true)) {
            $where .= ' AND estado = 1';
        }
        $filas = DB::todos(
            "SELECT id, {$meta['label']} AS etiqueta FROM $tabla WHERE $where ORDER BY {$meta['label']}",
            Empresa::param());
        return array_column($filas, 'etiqueta', 'id');
    }

    public static function buscar(string $tabla, int $id): ?array
    {
        self::asegurar($tabla);
        return DB::uno("SELECT * FROM $tabla WHERE id = :id AND " . Empresa::filtro(),
            Empresa::param() + [':id' => $id]);
    }

    public static function guardar(string $tabla, array $datos, ?int $id = null): int
    {
        $meta   = self::asegurar($tabla);
        $limpio = [];
        foreach ($meta['campos'] as $campo) {
            if (!array_key_exists($campo, $datos)) {
                continue;
            }
            $valor = is_string($datos[$campo]) ? trim($datos[$campo]) : $datos[$campo];
            if ($campo === 'estado' || $campo === 'decimales') {
                $valor = (int) $valor;
            } elseif ($valor === '') {
                $valor = null;
            }
            $limpio[$campo] = $valor;
        }
        if (!$limpio) {
            throw new InvalidArgumentException('No hay datos para guardar.');
        }

        if ($id === null) {
            $nuevo = DB::insertar($tabla, Empresa::sello($limpio));
            Auditoria::registrar('CREAR', $tabla, $nuevo, $limpio);
            return $nuevo;
        }

        if (!self::buscar($tabla, $id)) {
            throw new RuntimeException('El registro no pertenece a la empresa activa.');
        }
        DB::actualizar($tabla, $limpio, 'id = :id AND ' . Empresa::filtro(),
            Empresa::param() + [':id' => $id]);
        Auditoria::registrar('EDITAR', $tabla, $id, $limpio);
        return $id;
    }

    public static function eliminar(string $tabla, int $id): void
    {
        self::asegurar($tabla);
        if (!self::buscar($tabla, $id)) {
            throw new RuntimeException('El registro no pertenece a la empresa activa.');
        }
        try {
            DB::eliminar($tabla, 'id = :id AND ' . Empresa::filtro(), Empresa::param() + [':id' => $id]);
            Auditoria::registrar('ELIMINAR', $tabla, $id);
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1451) {
                throw new RuntimeException('No se puede eliminar: el registro está en uso por otros datos.');
            }
            throw $e;
        }
    }
}
